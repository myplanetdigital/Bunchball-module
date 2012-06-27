<?php
/**
 * @file
 *    Bunchball API classes.
 */

interface NitroAPI {
  /**
   * Log in to set session.
   *
   * @param $userName
   *    User name
   *
   *  @param $firstName
   *    optional.  Does not need to be the user's real first name
   *
   *  @param $lastName
   *    option. Does not need to be the user's real last name
   */
  public function login($userName, $firstName ='', $lastName = '');
  
  /**
   * Log an action for the established session.
   *
   * @param $userName
   *    the user name to record info for
   * @param $actionTag
   *    The action tag to log
   * @param $value
   *    Value associated with the action tag
   *
   * @throws NitroAPI_NoSessionException
   */
  public function logAction($actionTag, $value = '');
  
  /**
   * Return the user point balance for current session.
   *
   * @param $userName
   *    the user name to record info for
   *
   * @return
   *    the user point balance
   */
  public function getUserPointsBalance();
  
  /**
   * Retrieve site action leaders.
   *
   * @param $userName
   *    the user name to record info for
   * @param $actionTag
   *    action tag to retrieve
   * @return
   *    array containing leaders
   */
  public function getSiteActionLeaders($actionTag);
  
  /**
   * Retrieve the user's current level.
   * 
   * @return
   *    user's level
   */
  public function getLevel();
  
    /**
   * Add user to a group.
   * 
   * @param $group 
   *    Group to which user is added.
   */
  public function addUserToGroup($group);

  /**
   * Register callbacks to be run at various events.
   * 
   * @param $object
   *    the object on which to run the callback.
   * 
   * @param $event
   *    the event on which to run the callback. eg. 'postLogin'
   * 
   * @param $function
   *    the callback function to call
   */
  public function registerCallback($object, $event, $function);
  
}

class NitroAPI_Factory {
    // singleton instance
  private static $instance;

    /**
   * Implement singleton pattern.
   *
   * @return singleton instance of this class
   */
  public static function getInstance($type = 'XML') {
    if (!isset(self::$instance)) {
      $className = "NitroAPI_$type";
      self::$instance = new $className;
    }
    return self::$instance;
  }
}

class NitroAPI_XML implements NitroAPI {

  private $baseURL;
  private $secretKey;
  private $apiKey;
  private $userName;
  private $sessionKey;
  private $user_roles;
  private $callbacks;
  protected $is_logged_in = FALSE;
  protected $is_session_from_cache = FALSE;

  // Constants
  private $CRITERIA_MAX = "MAX";
  private $CRITERIA_CREDITS = "credits";
  private $POINT_CATEGORY_ALL = "all";
  private $TAGS_OPERATOR_OR = "OR";
  private $DEFINED_CALLBACKS = array('postLogin');

  /**
   * Constructor
   */
  public function __construct() {
    switch (variable_get('bunchball_environment')) {
      case 'production':
        $this->baseURL = variable_get('bunchball_production_url');

        break;

      case 'sandbox':
        $this->baseURL = variable_get('bunchball_sandbox_url');
        break;

      default:
        break;
    }
    $this->apiKey = variable_get('bunchball_apikey');
    $this->secretKey = variable_get('bunchball_apisecret');
    $this->callbacks = array();
  }

  /**
   * Generate a signature from the api key, secret and username.
   *
   * @return the signature
   */
  public function getSignature() {
    $unencryptedSignature = $this->apiKey . $this->secretKey . time() . $this->userName;

    // get the length
    $length = strlen($unencryptedSignature);

    //append the length to the signature
    $unencryptedSignature = $unencryptedSignature . $length;

    //MD5 on signature
    $signature = md5($unencryptedSignature);
    return $signature;
  }

  /**
   *  Parse Nitro XML response as array of attributes and values.
   *
   * @param $url
   *   XML to parse
   *
   * @return
   *   Array of values
   */
  private function my_xml2array($url) {
    $xml_values = array();
    //$result = drupal_http_request($url);
    
    $result = '';
    // Setup callback options array; call drupal_http_request in the background.
    $callback_options = array(
      array(
        'function' => 'drupal_http_request',
        'return' => &$result,
      ),
      $url,
    );
    // Queue up the request.
    httprl_queue_background_callback($callback_options);

    // Execute request.
    httprl_send_request();
    
    if (gettype($result) != "object") {
      return array();
    }
    
    $contents = $result->data;
    $parser = xml_parser_create('');
    if (!$parser)
      return FALSE;

    xml_parser_set_option($parser, XML_OPTION_TARGET_ENCODING, 'UTF-8');
    xml_parser_set_option($parser, XML_OPTION_CASE_FOLDING, 0);
    xml_parser_set_option($parser, XML_OPTION_SKIP_WHITE, 1);
    xml_parse_into_struct($parser, trim($contents), $xml_values);
    xml_parser_free($parser);
    if (!$xml_values)
      return array();

    $xml_array = array();
    $last_tag_ar = & $xml_array;
    $parents = array();
    $last_counter_in_tag = array(1 => 0);
    foreach ($xml_values as $data) {
      switch ($data['type']) {
        case 'open':
          $last_counter_in_tag[$data['level'] + 1] = 0;
          $new_tag = array('name' => $data['tag']);
          if (isset($data['attributes']))
            $new_tag['attributes'] = $data['attributes'];
          if (isset($data['value']) && trim($data['value']))
            $new_tag['value'] = trim($data['value']);
          $last_tag_ar[$last_counter_in_tag[$data['level']]] = $new_tag;
          $parents[$data['level']] = & $last_tag_ar;
          $last_tag_ar = & $last_tag_ar[$last_counter_in_tag[$data['level']]++];
          break;
        case 'complete':
          $new_tag = array('name' => $data['tag']);
          if (isset($data['attributes']))
            $new_tag['attributes'] = $data['attributes'];
          if (isset($data['value']) && trim($data['value']))
            $new_tag['value'] = trim($data['value']);

          $last_tag_ar[$last_counter_in_tag[$data['level']]++] = $new_tag;
          break;
        case 'close':
          $last_tag_ar = & $parents[$data['level']];
          break;
        default:
          break;
      };
    }
    return $xml_array;
  }

  /**
   *  Access the attribute values like XPATH
   *
   * @param $xml_tree
   * @param $tag_path
   * @return
   *    values
   */
  private function get_value_by_path($xml_tree, $tag_path) {
    $tmp_arr = & $xml_tree;
    $tag_path_array = explode('/', $tag_path);
    foreach ($tag_path_array as $tag_name) {
      $res = FALSE;
      foreach ($tmp_arr as $key => $node) {
        if (is_int($key) && $node['name'] == $tag_name) {
          $tmp_arr = $node;
          $res = TRUE;
          break;
        }
      }
      if (!$res)
        return FALSE;
    }
    return $tmp_arr;
  }

  /**
   * Log in to set session.
   *
   * @param $userName
   *    Do I use the user's GUID, or username, or email, or what?
   *    All of these can be stored in bunchball as user preferences, so you can
   *    always look them up.  But you still have to pick one to be the userId in
   *    our (bunchball) system.  This is what you will use to make API calls, so
   *    you should use the one that you will always have access to wherever you
   *    need to make an API call from.
   *
   *    For Drupal and the bunchball module, the default plugins for interacting
   *    with bunchball will assume a Drupal user id as the $userName.
   *
   *    If you need to use another ID (e.g. Janrain or other SSO id), then a
   *    plugin can extend the base plugin class and override the actual calls to
   *    the api
   *
   *  @param $firstName
   *    optional.  Does not need to be the user's real first name
   *
   *  @param $lastName
   *    option. Does not need to be the user's real last name
   *
   *    You can pass in optional firstName and lastName information for a user.
   *    These become stored as preferences (named 'firstName' and 'lastName')
   *    and can be looked up later using user.getPreference. They are also
   *    returned as part of the response to most site.* methods, such as
   *    site.getPointsLeaders. Note that you can put anything in these fields,
   *    like a username or email address. Think of them as custom data for a
   *    user that you don't want to have to lookup later when you're rendering
   *    leaderboards and similar things.
   *
   *    For Drupal 'firstName' is going to be the Drupal user name and 'lastName'
   *    user email.
   *
   *    As with 'userName' if different values are required, then the base plugin
   *    class can be overriden to pass different values (e.g. Actual first and
   *    last names as defined by fields added to the user entity)
   *
   */
  public function login($userName, $firstName = '', $lastName = '') {
    $this->userName = $userName;

    // Try retrieving the session from cache.
    $unique_id_type = variable_get('bunchball_unique_id', 'email');
    $cache_key = "$unique_id_type:$userName:$firstName:$lastName";
    $cache_entry = cache_get($cache_key, 'cache_bunchball_session');

    if ($cache_entry && $cache_entry->data && $cache_entry->expire > REQUEST_TIME) {
      $this->sessionKey = $cache_entry->data;
      $this->is_session_from_cache = TRUE;
    }
    else {
      $signature = $this->getSignature();

      // Construct a URL for REST API call user_login to extract Session Key
      $request = $this->baseURL .
        "?method=user.login" .
        "&apiKey={$this->apiKey}" .
        "&userId={$this->userName}" .
        "&ts=" . time() .
        "&sig=$signature" .
        "&firstName=$firstName" .
        "&lastName=$lastName";

      // Converting XML response attribute and values to array attributes and values
      $arr = $this->my_xml2array($request);

      // Accessing the sessionKey through XPATH
      $sessionKeyArray = $this->get_value_by_path($arr, 'Nitro/Login/sessionKey');
      $this->sessionKey = $sessionKeyArray['value'];

      // Cache expires in 72 hours - 1 minute.
      $expiration_time = REQUEST_TIME + ((72 * 60 * 60) - 60);
      cache_set($cache_key, $this->sessionKey, 'cache_bunchball_session', $expiration_time);
    }

    // Execute the postLogin callbacks for the first time.
    if (!$this->is_logged_in) {
      if (isset($this->callbacks['postLogin']) && is_array($this->callbacks['postLogin'])) {
        foreach ($this->callbacks['postLogin'] as $callback) {
          $callback['object']->$callback['function']();
        }
      }
      $this->is_logged_in = TRUE;
    }
  }

  /**
   * Log in using Drupal user.
   *
   * Setting Drupal roles is only happening if the user is not cached.
   *
   * @param $account
   *    Drupal user.
   */
  public function drupalLogin($account) {
    $this->login($account->uid, $account->name, md5($account->mail));
    if (!$this->is_session_from_cache) {
      $roles = $this->formatRoles($account->roles);
      $this->setPreferences($roles, TRUE);
    }
  }

  /**
   * Log an action for the established session.
   *
   * @param $userName
   *    the user name to record info for
   * 
   * @param $actionTag
   *    The action tag to log
   * 
   * @param $value
   *    Value associated with the action tag
   *
   * @throws NitroAPI_NoSessionException
   */
  public function logAction($actionTag, $value = '') {
    // Construct a URL for user logAction
    $request = "{$this->baseURL}?method=user.logAction" .
            "&sessionKey={$this->sessionKey}" .
            "&userId={$this->userName}" .
            "&tags=$actionTag" .
            "&value=$value";
    watchdog('bunchball', 'Log Action: %actionTag; value: %value', array('%actionTag' => $actionTag, '%value' => $value), WATCHDOG_INFO);
    //Converting XML response attribute and values to array attributes and values
    $arr = $this->my_xml2array($request);

    $responseArray = $this->get_value_by_path($arr, 'Nitro');
    if (! strcmp($responseArray['attributes']['res'], "ok") == 0) {
      throw new NitroAPI_LogActionException(t('Nitro API log action failed'));
    }
  }
  
  /**
   * Set preferences for current session.
   * 
   * @param $names
   *    Array of preferences to send EG: array("Key1" => "Value1", "Key2" => "Value2")
   * 
   * @param $key_value
   *    Treat array as key-value pairs if TRUE.
   *    EG:
   *      TRUE: send &names=Key1|Key2&values=Value1|Value2
   *      FALSE: send &names=Value1|Value2
   */
  public function setPreferences($names, $key_value = FALSE) {
    
    if ($key_value) {
      
      $names_list = str_replace(' ', '_', implode('|', array_keys($names)));
      $values_list = str_replace(' ', '_', implode('|', array_values($names)));
      // Construct a URL for user setPreferences
      $request = "{$this->baseURL}?method=user.setPreferences" .
              "&sessionKey={$this->sessionKey}" .
              "&userId={$this->userName}" .
              "&names=$names_list" .
              "&values=$values_list";
    }
    else {
      $names_list = str_replace(' ', '_', implode('|', array_values($names)));
      $request = "{$this->baseURL}?method=user.setPreferences" .
              "&sessionKey={$this->sessionKey}" .
              "&userId={$this->userName}" .
              "&names=$names_list";
    }
    //Converting XML response attribute and values to array attributes and values
    $arr = $this->my_xml2array($request);
    $responseArray = $this->get_value_by_path($arr, 'Nitro');
    if (! strcmp($responseArray['attributes']['res'], "ok") == 0) {
      throw new NitroAPI_LogActionException(t('Nitro API setPreferences failed'));
    }
  }
  
  /**
   * Get the current user's level.
   * 
   * @return
   *    user's level
   */
  public function getLevel() {
    // Construct a URL for user logAction
    $request = "{$this->baseURL}?method=user.getLevel" .
            "&sessionKey={$this->sessionKey}";
    watchdog('bunchball', 'Get level - user: %username.', array('%username' => $this->userName), WATCHDOG_INFO);
    //Converting XML response attribute and values to array attributes and values
    $arr = $this->my_xml2array($request);
    $responseArray = $this->get_value_by_path($arr, 'Nitro');
    if (! strcmp($responseArray['attributes']['res'], "ok") == 0) {
      throw new NitroAPI_LogActionException(t('Nitro API log action failed'));
    }
    $levelArray = $this->get_value_by_path($arr, 'Nitro/users/User/SiteLevel');
    if (isset($levelArray['attributes']['name'])) {
      return $levelArray['attributes']['name'];
    }
  }

  /**
   * Add user to a group.
   *
   * @param $group
   *    Group to which user is added.
   */
  public function addUserToGroup($group) {
    // Construct a URL for user logAction
    $request = "{$this->baseURL}?method=site.addUsersToGroup" .
            "&sessionKey={$this->sessionKey}" .
            "&groupName=$group" .
            "&userIds={$this->userName}";
    watchdog('bunchball', 'Add user to group - user: %username group: %group.',
            array('%username' => $this->userName, '%group' => $group), WATCHDOG_INFO);
    //Converting XML response attribute and values to array attributes and values
    $arr = $this->my_xml2array($request);
    $responseArray = $this->get_value_by_path($arr, 'Nitro');
    if (! strcmp($responseArray['attributes']['res'], "ok") == 0) {
      throw new NitroAPI_LogActionException(t('Nitro API log action failed'));
    }
  }

  /**
   * Return the user point balance for current session.
   *
   * @return
   *    the user point balance
   */
  public function getUserPointsBalance() {
    // Construct a URL to get point balance from user
    $request = $this->baseURL .
            "?method=user.getPointsBalance" .
            "&sessionKey=" . $this->sessionKey .
            "&start=0" . "&pointCategory=" .
            $this->POINT_CATEGORY_ALL . "&criteria=" .
            $this->CRITERIA_CREDITS . '&userId=' . $this->userName;

    //Converting XML response attribute and values to array attributes and values
    $arr = $this->my_xml2array($request);

    //Accessing the Balance attributes through XPATH and extracting points information
    return $this->get_value_by_path($arr, 'Nitro/Balance');
  }

  /**
   * Retrieve site action leaders.
   *
   * @param $actionTag
   *    action tag to retrieve
   * 
   * @return
   *    array containing leaders
   */
  public function getSiteActionLeaders($actionTag) {
    // Construct a URL to get action leaders
    $request = $this->baseURL .
            "?method=site.getActionLeaders" .
            "&sessionKey=" . $this->sessionKey .
            "&tags=" . $actionTag .
            "&tagsOperator=" . $this->TAGS_OPERATOR_OR .
            "&criteria=" . $this->CRITERIA_MAX .
            "&returnCount=" . $this->value;

    //Converting XML response attribute and values to array attributes and values
    $arr = $this->my_xml2array($request);

    //Accessing the Actions attributes through XPATH and extracting action leaders information
    $actionsArray = $this->get_value_by_path($arr, 'Nitro/actions/Action');
    return $actionsArray['attributes'];
  }

  /**
   * Format the roles so that they are consistent and in the format expected by 
   * nitro.
   * 
   * @param $roles
   *    Drupal user roles array IE: $user->roles
   * 
   * @return
   *    array of user roles for nitro. EG:
   *      array(['authenticated user'] => 1, ['administrator'] => 1)
   */
  private function formatRoles($roles) {
    $formatted_roles = array();
    if (empty($this->user_roles)) {
      // keep the results so we only call once
      $this->user_roles = user_roles();
    }
    $role_names = array_intersect_key($this->user_roles, $roles);
    $formatted_roles = array_fill_keys($role_names, 1);
    return $formatted_roles;
  }
  
  /**
   * Register callbacks to be run at various events.
   * 
   * @param $object
   *    the object on which to run the callback.
   * 
   * @param $event
   *    the event on which to run the callback. eg. 'postLogin'
   * 
   * @param $function
   *    the callback function to call
   */
  public function registerCallback($object, $event, $function) {
    if (in_array($event, $this->DEFINED_CALLBACKS)) {
      $this->callbacks[$event][] = array('object' => $object, 'function' => $function);
    }
    else {
      throw new NitroAPI_Exception(t('Undefined callback: %function', array('%function', $function)));
    }
  }
}

/**
 * General Nitro API exception.
 */
class NitroAPI_Exception extends Exception {}

/**
 * Exception to be thrown when log action is unsuccessful.
 */
class NitroAPI_LogActionException extends NitroAPI_Exception {}

/**
 * Exception to be thrown on HTTP error
 */
class NitroAPI_HttpException extends NitroAPI_Exception {}