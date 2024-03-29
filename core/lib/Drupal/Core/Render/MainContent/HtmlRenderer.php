<?php

/**
 * @file
 * Contains \Drupal\Core\Render\MainContent\HtmlRenderer.
 */

namespace Drupal\Core\Render\MainContent;

use Drupal\Component\Plugin\PluginManagerInterface;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Controller\TitleResolverInterface;
use Drupal\Core\Display\PageVariantInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Render\PageDisplayVariantSelectionEvent;
use Drupal\Core\Render\RenderEvents;
use Drupal\Core\Routing\RouteMatchInterface;
use Symfony\Component\DependencyInjection\ContainerAwareTrait;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Default main content renderer for HTML requests.
 */
class HtmlRenderer implements MainContentRendererInterface {

  /**
   * The title resolver.
   *
   * @var \Drupal\Core\Controller\TitleResolverInterface
   */
  protected $titleResolver;

  /**
   * The display variant manager.
   *
   * @var \Drupal\Component\Plugin\PluginManagerInterface
   */
  protected $displayVariantManager;

  /**
   * The event dispatcher.
   *
   * @var \Symfony\Component\EventDispatcher\EventDispatcherInterface
   */
  protected $eventDispatcher;

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * Constructs a new HtmlRenderer.
   *
   * @param \Drupal\Core\Controller\TitleResolverInterface $title_resolver
   *   The title resolver.
   * @param \Drupal\Component\Plugin\PluginManagerInterface $display_variant_manager
   *   The display variant manager.
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $event_dispatcher
   *   The event dispatcher.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   */
  public function __construct(TitleResolverInterface $title_resolver, PluginManagerInterface $display_variant_manager, EventDispatcherInterface $event_dispatcher, ModuleHandlerInterface $module_handler) {
    $this->titleResolver = $title_resolver;
    $this->displayVariantManager = $display_variant_manager;
    $this->eventDispatcher = $event_dispatcher;
    $this->moduleHandler = $module_handler;
  }

  /**
   * {@inheritdoc}
   *
   * The entire HTML: takes a #type 'page' and wraps it in a #type 'html'.
   */
  public function renderResponse(array $main_content, Request $request, RouteMatchInterface $route_match) {
    list($page, $title) = $this->prepare($main_content, $request, $route_match);

    if (!isset($page['#type']) || $page['#type'] !== 'page') {
      throw new \LogicException('Must be #type page');
    }

    $page['#title'] = $title;

    // Now render the rendered page.html.twig template inside the html.html.twig
    // template, and use the bubbled #attached metadata from $page to ensure we
    // load all attached assets.
    $html = [
      '#type' => 'html',
      'page' => $page,
    ];
    $html += element_info('html');

    // The special page regions will appear directly in html.html.twig, not in
    // page.html.twig, hence add them here, just before rendering html.html.twig.
    $this->buildPageTopAndBottom($html);

    // The three parts of rendered markup in html.html.twig (page_top, page and
    // page_bottom) must be rendered with drupal_render_root(), so that their
    // #post_render_cache callbacks are executed (which may attach additional
    // assets).
    // html.html.twig must be able to render the final list of attached assets,
    // and hence may not execute any #post_render_cache_callbacks (because they
    // might add yet more assets to be attached), and therefore it must be
    // rendered with drupal_render(), not drupal_render_root().
    drupal_render_root($html['page']);
    if (isset($html['page_top'])) {
      drupal_render_root($html['page_top']);
    }
    if (isset($html['page_bottom'])) {
      drupal_render_root($html['page_bottom']);
    }
    $content = drupal_render($html);

    // Store the cache tags associated with this page in a X-Drupal-Cache-Tags
    // header. Also associate the "rendered" cache tag. This allows us to
    // invalidate the entire render cache, regardless of the cache bin.
    $cache_tags = Cache::mergeTags(
      isset($html['page_top']) ? $html['page_top']['#cache']['tags'] : [],
      $html['page']['#cache']['tags'],
      isset($html['page_bottom']) ? $html['page_bottom']['#cache']['tags'] : [],
      ['rendered']
    );

    // Set the generator in the HTTP header.
    list($version) = explode('.', \Drupal::VERSION, 2);

    return new Response($content, 200,[
      'X-Drupal-Cache-Tags' => implode(' ', $cache_tags),
      'X-Generator' => 'Drupal ' . $version . ' (http://drupal.org)'
    ]);
  }

  /**
   * Prepares the HTML body: wraps the main content in #type 'page'.
   *
   * @param array $main_content
   *   The render array representing the main content.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object, for context.
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match, for context.
   *
   * @return array
   *   An array with two values:
   *   0. A #type 'page' render array.
   *   1. The page title.
   *
   * @throws \LogicException
   *   If the selected display variant does not implement PageVariantInterface.
   */
  protected function prepare(array $main_content, Request $request, RouteMatchInterface $route_match) {
    // If the _content result already is #type => page, we have no work to do:
    // the "main content" already is an entire "page" (see html.html.twig).
    if (isset($main_content['#type']) && $main_content['#type'] === 'page') {
      $page = $main_content;
    }
    // Otherwise, render it as the main content of a #type => page, by selecting
    // page display variant to do that and building that page display variant.
    else {
      // Select the page display variant to be used to render this main content,
      // default to the built-in "simple page".
      $event = new PageDisplayVariantSelectionEvent('simple_page', $route_match);
      $this->eventDispatcher->dispatch(RenderEvents::SELECT_PAGE_DISPLAY_VARIANT, $event);
      $variant_id = $event->getPluginId();

      // We must render the main content now already, because it might provide a
      // title. We set its $is_root_call parameter to FALSE, to ensure
      // #post_render_cache callbacks are not yet applied. This is essentially
      // "pre-rendering" the main content, the "full rendering" will happen in
      // ::renderContentIntoResponse().
      // @todo Remove this once https://www.drupal.org/node/2359901 lands.
      if (!empty($main_content)) {
        drupal_render($main_content, FALSE);
        $main_content = [
          '#markup' => $main_content['#markup'],
          '#attached' => $main_content['#attached'],
          '#cache' => ['tags' => $main_content['#cache']['tags']],
          '#post_render_cache' => $main_content['#post_render_cache'],
          '#title' => isset($main_content['#title']) ? $main_content['#title'] : NULL,
        ];
      }

      // Instantiate the page display, and give it the main content.
      $page_display = $this->displayVariantManager->createInstance($variant_id);
      if (!$page_display instanceof PageVariantInterface) {
        throw new \LogicException('Cannot render the main content for this page because the provided display variant does not implement PageVariantInterface.');
      }
      $page_display->setMainContent($main_content);

      // Generate a #type => page render array using the page display variant,
      // the page display will build the content for the various page regions.
      $page = array(
        '#type' => 'page',
      );
      $page += $page_display->build();
    }

    // $page is now fully built. Find all non-empty page regions, and add a
    // theme wrapper function that allows them to be consistently themed.
    $regions = system_region_list(\Drupal::theme()->getActiveTheme()->getName());
    foreach (array_keys($regions) as $region) {
      if (!empty($page[$region])) {
        $page[$region]['#theme_wrappers'][] = 'region';
        $page[$region]['#region'] = $region;
      }
    }

    // Allow hooks to add attachments to $page['#attached'].
    $this->invokePageAttachmentHooks($page);

    // Determine the title: use the title provided by the main content if any,
    // otherwise get it from the routing information.
    $title = isset($main_content['#title']) ? $main_content['#title'] : $this->titleResolver->getTitle($request, $route_match->getRouteObject());

    return [$page, $title];
  }

  /**
   * Invokes the page attachment hooks.
   *
   * @param array &$page
   *   A #type 'page' render array, for which the page attachment hooks will be
   *   invoked and to which the results will be added.
   *
   * @throws \LogicException
   *
   * @internal
   *
   * @see hook_page_attachments()
   * @see hook_page_attachments_alter()
   */
  public function invokePageAttachmentHooks(array &$page) {
    // Modules can add attachments.
    $attachments = [];
    foreach ($this->moduleHandler->getImplementations('page_attachments') as $module) {
      $function = $module . '_page_attachments';
      $function($attachments);
    }
    if (array_diff(array_keys($attachments), ['#attached', '#post_render_cache']) !== []) {
      throw new \LogicException('Only #attached and #post_render_cache may be set in hook_page_attachments().');
    }

    // Modules and themes can alter page attachments.
    $this->moduleHandler->alter('page_attachments', $attachments);
    \Drupal::theme()->alter('page_attachments', $attachments);
    if (array_diff(array_keys($attachments), ['#attached', '#post_render_cache']) !== []) {
      throw new \LogicException('Only #attached and #post_render_cache may be set in hook_page_attachments_alter().');
    }

    // Merge the attachments onto the $page render array.
    $page['#attached'] = isset($page['#attached']) ? $page['#attached'] : [];
    $page['#post_render_cache'] = isset($page['#post_render_cache']) ? $page['#post_render_cache'] : [];
    if (isset($attachments['#attached'])) {
      $page['#attached'] = drupal_merge_attached($page['#attached'], $attachments['#attached']);
    }
    if (isset($attachments['#post_render_cache'])) {
      $page['#post_render_cache'] = NestedArray::mergeDeep($page['#post_render_cache'], $attachments['#post_render_cache']);
    }
  }

  /**
   * Invokes the page top and bottom hooks.
   *
   * @param array &$html
   *   A #type 'html' render array, for which the page top and bottom hooks will
   *   be invoked, and to which the 'page_top' and 'page_bottom' children (also
   *   render arrays) will be added (if non-empty).
   *
   * @throws \LogicException
   *
   * @internal
   *
   * @see hook_page_top()
   * @see hook_page_bottom()
   * @see html.html.twig
   */
  public function buildPageTopAndBottom(array &$html) {
    // Modules can add render arrays to the top and bottom of the page.
    $page_top = [];
    $page_bottom = [];
    foreach ($this->moduleHandler->getImplementations('page_top') as $module) {
      $function = $module . '_page_top';
      $function($page_top);
    }
    foreach ($this->moduleHandler->getImplementations('page_bottom') as $module) {
      $function = $module . '_page_bottom';
      $function($page_bottom);
    }
    if (!empty($page_top)) {
      $html['page_top'] = $page_top;
    }
    if (!empty($page_bottom)) {
      $html['page_bottom'] = $page_bottom;
    }
  }

}
