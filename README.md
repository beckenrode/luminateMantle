# luminateMantle
Luminate Mantle is a Wordpress Plugin for use with Luminate Online. It provides a wrapper around the Luminate Online REST API.

luminateMantle Example Use
-------------------

```  php
/* Verify that the luminateMantle Plugin is activated */
if ( ! apply_filters( 'luminate-mantle-installed', false ) ) {
	echo "The Luminate Mantle Plugin isn't loaded!";
} else {
	/* Initalize the plugin */
	$LuminateMantle = new LuminateMantle();

	$data = array(
		'servlet' => 'teamraiser',
		'method' => 'getTeamraisersByInfo',
		'params' => array(
			'list_page_size' => 10,
			'event_type' => '',
			'name' => '%search term%'
		)
	);
                        
	$request = array('data' => http_build_query($data));
                        
	echo $LuminateMantle->luminateMantle($request);
}
```