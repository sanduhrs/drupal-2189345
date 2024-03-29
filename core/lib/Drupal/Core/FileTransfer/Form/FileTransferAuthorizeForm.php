<?php

/**
 * @file
 * Contains \Drupal\Core\FileTransfer\Form\FileTransferAuthorizeForm.
 */

namespace Drupal\Core\FileTransfer\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides the file transfer authorization form.
 */
class FileTransferAuthorizeForm extends FormBase {

  /**
   * The app root.
   *
   * @var string
   */
  protected  $root;

  /**
   * Constructs a new FileTransferAuthorizeForm object.
   *
   * @param string $root
   *   The app root.
   */
  public function __construct($root) {
    $this->root = $root;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static ($container->get('app.root'));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormID() {
    return 'authorize_filetransfer_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    // If possible, we want to post this form securely via HTTPS.
    $form['#https'] = TRUE;

    // Get all the available ways to transfer files.
    if (empty($_SESSION['authorize_filetransfer_info'])) {
      drupal_set_message($this->t('Unable to continue, no available methods of file transfer'), 'error');
      return array();
    }
    $available_backends = $_SESSION['authorize_filetransfer_info'];

    if (!$this->getRequest()->isSecure()) {
      $form['information']['https_warning'] = array(
        '#prefix' => '<div class="messages messages--error">',
        '#markup' => $this->t('WARNING: You are not using an encrypted connection, so your password will be sent in plain text. <a href="@https-link">Learn more</a>.', array('@https-link' => 'http://drupal.org/https-information')),
        '#suffix' => '</div>',
      );
    }

    // Decide on a default backend.
    if ($authorize_filetransfer_default = $form_state->getValue(array('connection_settings', 'authorize_filetransfer_default')));
    elseif ($authorize_filetransfer_default = $this->config('system.authorize')->get('filetransfer_default'));
    else {
      $authorize_filetransfer_default = key($available_backends);
    }

    $form['information']['main_header'] = array(
      '#prefix' => '<h3>',
      '#markup' => $this->t('To continue, provide your server connection details'),
      '#suffix' => '</h3>',
    );

    $form['connection_settings']['#tree'] = TRUE;
    $form['connection_settings']['authorize_filetransfer_default'] = array(
      '#type' => 'select',
      '#title' => $this->t('Connection method'),
      '#default_value' => $authorize_filetransfer_default,
      '#weight' => -10,
    );

    /*
     * Here we create two submit buttons. For a JS enabled client, they will
     * only ever see submit_process. However, if a client doesn't have JS
     * enabled, they will see submit_connection on the first form (when picking
     * what filetransfer type to use, and submit_process on the second one (which
     * leads to the actual operation).
     */
    $form['submit_connection'] = array(
      '#prefix' => "<br style='clear:both'/>",
      '#name' => 'enter_connection_settings',
      '#type' => 'submit',
      '#value' => $this->t('Enter connection settings'),
      '#weight' => 100,
    );

    $form['submit_process'] = array(
      '#name' => 'process_updates',
      '#type' => 'submit',
      '#value' => $this->t('Continue'),
      '#weight' => 100,
    );

    // Build a container for each connection type.
    foreach ($available_backends as $name => $backend) {
      $form['connection_settings']['authorize_filetransfer_default']['#options'][$name] = $backend['title'];
      $form['connection_settings'][$name] = array(
        '#type' => 'container',
        '#attributes' => array('class' => array("filetransfer-$name", 'filetransfer')),
        '#states' => array(
          'visible' => array(
            'select[name="connection_settings[authorize_filetransfer_default]"]' => array('value' => $name),
          ),
        ),
      );
      // We can't use #prefix on the container itself since then the header won't
      // be hidden and shown when the containers are being manipulated via JS.
      $form['connection_settings'][$name]['header'] = array(
        '#markup' => '<h4>' . $this->t('@backend connection settings', array('@backend' => $backend['title'])) . '</h4>',
      );

      $form['connection_settings'][$name] += $this->addConnectionSettings($name);

      // Start non-JS code.
      if ($form_state->getValue(array('connection_settings', 'authorize_filetransfer_default')) == $name) {

        // Change the submit button to the submit_process one.
        $form['submit_process']['#attributes'] = array();
        unset($form['submit_connection']);

        // Activate the proper filetransfer settings form.
        $form['connection_settings'][$name]['#attributes']['style'] = 'display:block';
        // Disable the select box.
        $form['connection_settings']['authorize_filetransfer_default']['#disabled'] = TRUE;

        // Create a button for changing the type of connection.
        $form['connection_settings']['change_connection_type'] = array(
          '#name' => 'change_connection_type',
          '#type' => 'submit',
          '#value' => $this->t('Change connection type'),
          '#weight' => -5,
          '#attributes' => array('class' => array('filetransfer-change-connection-type')),
        );
      }
      // End non-JS code.
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    // Only validate the form if we have collected all of the user input and are
    // ready to proceed with updating or installing.
    if ($form_state->getTriggeringElement()['#name'] != 'process_updates') {
      return;
    }

    if ($form_connection_settings = $form_state->getValue('connection_settings')) {
      $backend = $form_connection_settings['authorize_filetransfer_default'];
      $filetransfer = $this->getFiletransfer($backend, $form_connection_settings[$backend]);
      try {
        if (!$filetransfer) {
          throw new \Exception($this->t('The connection protocol %backend does not exist.', array('%backend' => $backend)));
        }
        $filetransfer->connect();
      }
      catch (\Exception $e) {
        // The format of this error message is similar to that used on the
        // database connection form in the installer.
        $form_state->setErrorByName('connection_settings', $this->t('Failed to connect to the server. The server reports the following message: !message For more help installing or updating code on your server, see the <a href="@handbook_url">handbook</a>.', array(
          '!message' => '<p class="error">' . $e->getMessage()  . '</p>',
          '@handbook_url' => 'http://drupal.org/documentation/install/modules-themes',
        )));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $form_connection_settings = $form_state->getValue('connection_settings');
    switch ($form_state->getTriggeringElement()['#name']) {
      case 'process_updates':

        // Save the connection settings to the DB.
        $filetransfer_backend = $form_connection_settings['authorize_filetransfer_default'];

        // If the database is available then try to save our settings. We have
        // to make sure it is available since this code could potentially (will
        // likely) be called during the installation process, before the
        // database is set up.
        try {
          $connection_settings = array();
          foreach ($form_connection_settings[$filetransfer_backend] as $key => $value) {
            // We do *not* want to store passwords in the database, unless the
            // backend explicitly says so via the magic #filetransfer_save form
            // property. Otherwise, we store everything that's not explicitly
            // marked with #filetransfer_save set to FALSE.
            if (!isset($form['connection_settings'][$filetransfer_backend][$key]['#filetransfer_save'])) {
              if ($form['connection_settings'][$filetransfer_backend][$key]['#type'] != 'password') {
                $connection_settings[$key] = $value;
              }
            }
            // The attribute is defined, so only save if set to TRUE.
            elseif ($form['connection_settings'][$filetransfer_backend][$key]['#filetransfer_save']) {
              $connection_settings[$key] = $value;
            }
          }
          // Set this one as the default authorize method.
          $this->config('system.authorize')->set('filetransfer_default', $filetransfer_backend);
          // Save the connection settings minus the password.
          $this->config('system.authorize')->set('filetransfer_connection_settings_' . $filetransfer_backend, $connection_settings);

          $filetransfer = $this->getFiletransfer($filetransfer_backend, $form_connection_settings[$filetransfer_backend]);

          // Now run the operation.
          $this->runOperation($filetransfer);
        }
        catch (\Exception $e) {
          // If there is no database available, we don't care and just skip
          // this part entirely.
        }

        break;

      case 'enter_connection_settings':
        $form_state->setRebuild();
        break;

      case 'change_connection_type':
        $form_state->setRebuild();
        $form_state->unsetValue(array('connection_settings', 'authorize_filetransfer_default'));
        break;
    }
  }

  /**
   * Gets a FileTransfer class for a specific transfer method and settings.
   *
   * @param $backend
   *   The FileTransfer backend to get the class for.
   * @param $settings
   *   Array of settings for the FileTransfer.
   *
   * @return \Drupal\Core\FileTransfer\FileTransfer|bool
   *   An instantiated FileTransfer object for the requested method and settings,
   *   or FALSE if there was an error finding or instantiating it.
   */
  protected function getFiletransfer($backend, $settings = array()) {
    $filetransfer = FALSE;
    if (!empty($_SESSION['authorize_filetransfer_info'][$backend])) {
      $backend_info = $_SESSION['authorize_filetransfer_info'][$backend];
      if (class_exists($backend_info['class'])) {
        $filetransfer = $backend_info['class']::factory($this->root, $settings);
      }
    }
    return $filetransfer;
  }

  /**
   * Generates the Form API array for a given connection backend's settings.
   *
   * @param string $backend
   *   The name of the backend (e.g. 'ftp', 'ssh', etc).
   *
   * @return array
   *   Form API array of connection settings for the given backend.
   *
   * @see hook_filetransfer_backends()
   */
  protected function addConnectionSettings($backend) {
    $auth_connection_config = $this->config('system.authorize')->get('filetransfer_connection_settings_' . $backend);
    $defaults = $auth_connection_config ? $auth_connection_config : array();
    $form = array();

    // Create an instance of the file transfer class to get its settings form.
    $filetransfer = $this->getFiletransfer($backend);
    if ($filetransfer) {
      $form = $filetransfer->getSettingsForm();
    }
    // Fill in the defaults based on the saved settings, if any.
    $this->setConnectionSettingsDefaults($form, NULL, $defaults);
    return $form;
  }

  /**
   * Sets the default settings on a file transfer connection form recursively.
   *
   * The default settings for the file transfer connection forms are saved in
   * the database. The settings are stored as a nested array in the case of a
   * settings form that has details or otherwise uses a nested structure.
   * Therefore, to properly add defaults, we need to walk through all the
   * children form elements and process those defaults recursively.
   *
   * @param $element
   *   Reference to the Form API form element we're operating on.
   * @param $key
   *   The key for our current form element, if any.
   * @param array $defaults
   *   The default settings for the file transfer backend we're operating on.
   */
  protected function setConnectionSettingsDefaults(&$element, $key, array $defaults) {
    // If we're operating on a form element which isn't a details, and we have
    // a default setting saved, stash it in #default_value.
    if (!empty($key) && isset($defaults[$key]) && isset($element['#type']) && $element['#type'] != 'details') {
      $element['#default_value'] = $defaults[$key];
    }
    // Now, we walk through all the child elements, and recursively invoke
    // ourselves on each one. Since the $defaults settings array can be nested
    // (because of #tree, any values inside details will be nested), if
    // there's a subarray of settings for the form key we're currently
    // processing, pass in that subarray to the recursive call. Otherwise, just
    // pass on the whole $defaults array.
    foreach (Element::children($element) as $child_key) {
      $this->setConnectionSettingsDefaults($element[$child_key], $child_key, ((isset($defaults[$key]) && is_array($defaults[$key])) ? $defaults[$key] : $defaults));
    }
  }

  /**
   * Runs the operation specified in $_SESSION['authorize_operation'].
   *
   * @param $filetransfer
   *   The FileTransfer object to use for running the operation.
   */
  protected function runOperation($filetransfer) {
    $operation = $_SESSION['authorize_operation'];
    unset($_SESSION['authorize_operation']);

    require_once $this->root . '/' . $operation['file'];
    call_user_func_array($operation['callback'], array_merge(array($filetransfer), $operation['arguments']));
  }

}
