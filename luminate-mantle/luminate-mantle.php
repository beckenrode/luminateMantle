<?php
/*
  Plugin Name: Luminate Mantle
  Plugin URI: https://github.com/beckenrode/luminateMantle
  Description: Luminate Mantle is a Wordpress Plugin for use with Luminate Online. It provides a wrapper around the Luminate Online REST API.
  Author: Brandon Eckenrode
  Version: 1.0
  Author URI: https://github.com/beckenrode
 */

/*
 *   Define the plugin class
 */

class LuminateMantle {
    /*
     *   Some required plugin information
     */

    public $luminate_mantle_host_name;
    public $luminate_mantle_short_name;
    public $luminate_mantle_api_key;
    public $luminate_mantle_login_name;
    public $luminate_mantle_login_password;
    public $v = '1.0';
    public $response_format = 'php';
    private $servlet;
    private $method;
    private $methodParams = array();

    /*
     *   Required __construct() function
     */

    function __construct() {

        /* Define admin menu and options page */
        if (is_admin()) {
            /* Define admin menu and options page */
            add_action('admin_menu', array(&$this, 'luminate_mantle_add_admin_menu'));
            add_action('admin_init', array(&$this, 'luminate_mantle_settings_init'));
        }

        /* Add filter for plugin */
        add_filter('luminate-mantle-installed', '__return_true');
    }

    /*
     *  Required luminateMantle() function
     */

    function luminateMantle($requestData) {
        if (empty($requestData)) {
            wp_send_json_error('Request Not Found');
        }

        $this->configuration();

        $data = $this->perpareData($requestData);

        /* Set API Request Parameters */
        $params = array();
        if (!empty($data['params'])) {
            foreach ($data['params'] as $pKey => $pVal) {
                $params[$pKey] = $pVal;
            }
        }

        /* Make API call */
        if (!empty($data['servlet']) && !empty($data['method'])) {

            $result = $this->call($data, $params);

            $response = array('success' => true);
        } else {

            $response = array('success' => false);
        }

        /* return the json results */
        wp_send_json_success($result);
    }

    /*
     *  Sets Configuration
     */

    function configuration() {
        $options = get_option('luminate_mantle_settings');
        if (empty($options)) {
            wp_send_json_error('Luminate Online Credentials Not Found');
        };

        foreach ($options as $oKey => $oVal) {
            $this->$oKey = $oVal;
        }
    }

    /*
     *  Prepares data for processing.
     */

    function perpareData($requestData) {

        if (empty($requestData['data'])) {
            wp_send_json_error('Request Data Not Found');
        }

        $data = array();
        parse_str($requestData['data'], $data);

        $servletShorthand = array(
            'addressbook',
            'advocacy',
            'connect',
            'cons',
            'content',
            'datasync',
            'donation',
            'event',
            'group',
            'orgevent',
            'recurring',
            'survey',
            'teamraiser'
        );

        if (in_array(strtolower($data['servlet']), $servletShorthand)) {
            /* define servlets that require CR */
            $cr = array(
                'AddressBookAPI',
                'ContentAPI',
                'OrgeventAPI',
                'SurveyAPI',
                'TeamraiserAPI'
            );

            /* add "SR" or "CR", capitalize the first letter, and add "API" */
            $data['servlet'] = ucfirst($data['servlet']) . 'API';
            $data['servlet'] = in_array($data['servlet'], $cr) ? 'CR' . $data['servlet'] : 'SR' . $data['servlet'];

            /* special cases where a letter in the middle of the servlet name needs to be capitalized */
            $data['servlet'] = str_replace('Addressbook', 'AddressBook', $data['servlet']);
            $data['servlet'] = str_replace('Datasync', 'DataSync', $data['servlet']);
            $data['servlet'] = str_replace('Orgevent', 'OrgEvent', $data['servlet']);
        } else {
            wp_send_json_error('Servlet Not Found');
        }

        return $data;
    }

    /*
     * 		Public facing interface for this plugin. This is the method that takes the parameters from whatever
     * 		controller is asking for the information and passes them on to the API. Whatever the response from the API
     * 		is, it is passed on to the controller.
     *
     * 		@param string $servletMethod A string combining the API servlet to be called and the method of that servlet
     * 		that should be called. The string should be in the format "ApiServlet_apiMethod." Example: SRConsAPI_getUser.
     *
     * 		@param array $params An array of API method specific parameters that are to be sent through to the api. The
     * 		indices of this array should correspond exactly to API parameters listed in the Convio Open API documentation
     * 		found at http://open.convio.com/api/apidoc/.
     *
     * 		@uses LuminateMantle::servlet
     * 		@uses LuminateMantle::method
     * 		@uses LuminateMantle::methodParams
     * 		@uses LuminateMantle::makeCall()
     *
     * 		@return This will return whatever the makeCall() method returns. That
     * 		will be a PHP object that is representative of the response from the API.
     */

    function call($data, $params = NULL) {
        $this->servlet = $data['servlet'];
        $this->method = $data['method'];

        if ($params !== NULL) {
            $this->methodParams = $params;
        }

        return $this->makeCall();
    }

    /*
     * 		This method is the heavy-lifting section of the plugin. After the URL has been correctly created and the
     * 		parameters are encoded properly, this method actually makes the call to the API. It first checks to see
     * 		if it has access to cURL, if so it uses that, if not it makes a simply fopen call. cURL is prefferable,
     * 		because we can get more information on the call
     *
     * 		@uses LuminateMantle::response_format
     * 		@uses LuminateMantle::getUrl()
     * 		@uses LuminateMantle::getPostdata()
     * 
     * 		@return This method will return the API response as a PHP object.
     */

    function makeCall() {

        if (!class_exists('WP_Http')) {
            include_once( ABSPATH . WPINC . '/class-http.php' );
        }

        $request = new WP_Http;
        $result = $request->request($this->getUrl(), array('method' => 'POST', 'body' => $this->getPostData()));

        if ($result['response']['code'] != '200') {
            wp_send_json_error('Error: ' . $result['response']['code']);
        }
        if (empty($result['body'])) {
            wp_send_json_error('Empty Response Received');
        }

        if ($this->response_format == 'php') {
            $result['body'] = json_decode($result['body']);
        }

        return $result['body'];
    }

    /*
     * 		Combines the given parameters into a valid API Servlet URL, which will be used to process the POSTed
     * 		parameters.
     *
     * 		@uses LuminateMantle::luminate_mantle_host
     * 		@uses LuminateMantle::luminate_mantle_short_name
     * 		@uses LuminateMantle::servlet
     *
     * 		@return Valid API Servlet URL ready to receive POSTed parameters.
     */

    function getUrl() {
        return sprintf('https://%s/%s/site/%s', $this->luminate_mantle_host_name, $this->luminate_mantle_short_name, $this->servlet);
    }

    /*
     * 		Compiles all the configuration parameters and method specific parameters together into one urlencoded
     * 		parameter string ready to be sent through to the Convio server via an HTTP POST.
     *
     * 		@uses LuminateMantle::response_format
     * 		@uses LuminateMantle::v
     * 		@uses LuminateMantle::luminate_mantle_api_key
     * 		@uses LuminateMantle::luminate_mantle_login_name
     * 		@uses LuminateMantle::luminate_mantle_login_password
     * 		@uses LuminateMantle::method
     * 		@uses LuminateMantle::methodParams
     *
     * 		@return A urlencoded parameter string that is ready for posting to the Convio API.
     */

    function getPostData() {

        $baseData = http_build_query(array(
            'v' => $this->v,
            'api_key' => $this->luminate_mantle_api_key,
            'response_format' => $this->response_format == 'php' ? 'json' : $this->response_format,
            'login_name' => $this->luminate_mantle_login_name,
            'login_password' => $this->luminate_mantle_login_password,
            'method' => $this->method
        ));
        $methodData = http_build_query($this->methodParams);
        return sprintf('%s&%s', $baseData, $methodData);
    }

    function luminate_mantle_add_admin_menu() {

        add_options_page('Luminate Mantle', 'Luminate Mantle', 'manage_options', 'luminate_mantle', array(&$this, 'luminate_mantle_options_page'));
    }

    function luminate_mantle_settings_init() {

        register_setting('pluginPage', 'luminate_mantle_settings');

        add_settings_section(
                'luminate_mantle_pluginPage_section', __('Luminate Online Settings', 'wordpress'), array(&$this, 'luminate_mantle_settings_section_callback'), 'pluginPage'
        );

        add_settings_field(
                'luminate_mantle_host_name', __('Host Name', 'wordpress'), array(&$this, 'luminate_mantle_host_name_render'), 'pluginPage', 'luminate_mantle_pluginPage_section'
        );

        add_settings_field(
                'luminate_mantle_short_name', __('Short Name', 'wordpress'), array(&$this, 'luminate_mantle_short_name_render'), 'pluginPage', 'luminate_mantle_pluginPage_section'
        );

        add_settings_field(
                'luminate_mantle_api_key', __('API Key', 'wordpress'), array(&$this, 'luminate_mantle_api_key_render'), 'pluginPage', 'luminate_mantle_pluginPage_section'
        );

        add_settings_field(
                'luminate_mantle_login_name', __('Login Name', 'wordpress'), array(&$this, 'luminate_mantle_login_name_render'), 'pluginPage', 'luminate_mantle_pluginPage_section'
        );

        add_settings_field(
                'luminate_mantle_login_password', __('Login Password', 'wordpress'), array(&$this, 'luminate_mantle_login_password_render'), 'pluginPage', 'luminate_mantle_pluginPage_section'
        );
    }

    function luminate_mantle_host_name_render() {

        $options = get_option('luminate_mantle_settings');
        ?>
        <input type='text' name='luminate_mantle_settings[luminate_mantle_host_name]' value='<?php echo $options['luminate_mantle_host_name']; ?>'>
        <?php
    }

    function luminate_mantle_short_name_render() {

        $options = get_option('luminate_mantle_settings');
        ?>
        <input type='text' name='luminate_mantle_settings[luminate_mantle_short_name]' value='<?php echo $options['luminate_mantle_short_name']; ?>'>
        <?php
    }

    function luminate_mantle_api_key_render() {

        $options = get_option('luminate_mantle_settings');
        ?>
        <input type='text' name='luminate_mantle_settings[luminate_mantle_api_key]' value='<?php echo $options['luminate_mantle_api_key']; ?>'>
        <?php
    }

    function luminate_mantle_login_name_render() {

        $options = get_option('luminate_mantle_settings');
        ?>
        <input type='text' name='luminate_mantle_settings[luminate_mantle_login_name]' value='<?php echo $options['luminate_mantle_login_name']; ?>'>
        <?php
    }

    function luminate_mantle_login_password_render() {

        $options = get_option('luminate_mantle_settings');
        ?>
        <input type='password' name='luminate_mantle_settings[luminate_mantle_login_password]' value='<?php echo $options['luminate_mantle_login_password']; ?>'>
        <?php
    }

    function luminate_mantle_settings_section_callback() {

        echo __('Please enter your Luminate Online REST API Credentials', 'wordpress');
    }

    function luminate_mantle_options_page() {
        ?>
        <form action='options.php' method='post'>

            <h2>Luminate Mantle</h2>

            <?php
            settings_fields('pluginPage');
            do_settings_sections('pluginPage');
            submit_button();
            ?>

        </form>
        <?php
    }

}

/*
 *   Initalize the plugin
 */
$LuminateMantle = new LuminateMantle();
