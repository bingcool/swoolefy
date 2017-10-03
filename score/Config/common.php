<?php
return [
	'cors' =>[
		'Origin' => ['*'],
        'Access-Control-Request-Method' => ['GET','POST','PUT','DELETE'],
        'Access-Control-Request-Headers' => ['X-Wsse'],
		'Access-Control-Allow-Credentials' => true,
		'Access-Control-Max-Age' => 3600,
		'Access-Control-Expose-Headers' => ['X-Pagination-Current-Page'],
	],
];