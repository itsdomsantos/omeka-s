<?php
return [
    'controllers' => [
        'factories' => [
            'AddTriplestore\Controller\Site\Index' => 'AddTriplestore\Controller\Site\IndexControllerFactory',
        ],
    ],
    'router' => [
        'routes' => [
            'site' => [
                'child_routes' => [
                    'add-triplestore' => [
                        'type' => 'Segment',
                        'options' => [
                            'route' => '/add-triplestore[/:action]',
                            'constraints' => [
                                'action' => '[a-zA-Z][a-zA-Z0-9_-]*',
                            ],
                            'defaults' => [
                                '__NAMESPACE__' => 'AddTriplestore\Controller\Site',
                                'controller' => 'Index',
                                'action' => 'index',
                            ],
                        ],
                        'may_terminate' => true,
                        'child_routes' => [
                            'upload' => [
                                'type' => 'Segment',
                                'options' => [
                                    'route' => '/upload',
                                    'defaults' => [
                                        'controller' => 'AddTriplestore\Controller\Site\Index',
                                        'action' => 'upload',
                                    ],
                                ],
                            ],
                            'process-collecting' => [
                                'type' => 'Segment',
                                'options' => [
                                    'route' => '/process-collecting',
                                    'defaults' => [
                                        'controller' => 'AddTriplestore\Controller\Site\Index',
                                        'action' => 'processCollectingForm',
                                    ],
                                ],
                            ],
                            'search' => [
                                'type' => 'Segment',
                                'options' => [
                                    'route' => '/search',
                                    'defaults' => [
                                        'controller' => 'AddTriplestore\Controller\Site\Index',
                                        'action' => 'search',
                                    ],
                                ],
                            ],
                            'login' => [
                            'type' => 'Literal',
                            'options' => [
                                'route' => '/login',
                                'defaults' => [
                                    'controller' => 'AddTriplestore\Controller\Site\Index',
                                    'action' => 'login',
                                ],
                            ],
                        ],
                        'signup' => [
                            'type' => 'Literal',
                            'options' => [
                                'route' => '/signup',
                                'defaults' => [
                                    'controller' => 'AddTriplestore\Controller\Site\Index',
                                    'action' => 'signup',
                                ],
                            ],
                        ],
                        'logout' => [
                            'type' => 'Literal',
                            'options' => [
                                'route' => '/logout',
                                'defaults' => [
                                    'controller' => 'AddTriplestore\Controller\Site\Index',
                                    'action' => 'logout',
                                ],
                            ],
                        ],
                        'sparql' => [
                            'type' => 'Literal',
                            'options' => [
                                'route' => '/sparql',
                                'defaults' => [
                                    'controller' => 'AddTriplestore\Controller\Site\Index',
                                    'action' => 'sparql',
                                ],
                            ],
                        ],
                            'view-details' => [
                                'type' => 'Segment',
                                'options' => [
                                    'route' => '/view-details',
                                    'defaults' => [
                                        'controller' => 'AddTriplestore\Controller\Site\Index',
                                        'action' => 'viewDetails',
                                    ],
                                ],
                            ],
                            'download-ttl' => [
                                'type' => 'Segment',
                                'options' => [
                                    'route' => '/download-ttl',
                                    'defaults' => [
                                        'controller' => 'AddTriplestore\Controller\Site\Index',
                                        'action' => 'downloadTtl',
                                    ],
                                ],
                            ],
                            'about-us' => [
                                'type' => 'Segment',
                                'options' => [
                                    'route' => '/about-us',
                                    'defaults' => [
                                        'controller' => 'AddTriplestore\Controller\Site\Index',
                                        'action' => 'aboutUs',
                                    ],
                                ],
                            ],
                            'dashboard' => [
                                'type' => 'Segment',
                                'options' => [
                                    'route' => '/dashboard',
                                    'defaults' => [
                                        'action' => 'dashboard',
                                    ],
                                ],
                            ],
                            'my-data' => [
                                'type' => 'Segment',
                                'options' => [
                                    'route' => '/my-data',
                                    'defaults' => [
                                        'action' => 'myData',
                                    ],
                                ],
                            ],
                            'download-template' => [
                                'type' => 'Segment',
                                'options' => [
                                    'route' => '/download-template',
                                    'defaults' => [
                                        'controller' => 'AddTriplestore\Controller\Site\Index',
                                        'action' => 'downloadTemplate',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'view_manager' => [
        'template_path_stack' => [
            __DIR__ . '/../view',
        ],
    ],
    'asset_manager' => [
        'resolver_configs' => [
            'paths' => [
                'AddTriplestore' => __DIR__ . '/../asset', 
            ],
        ],
    ],
    'navigation' => [
    'site' => [
        [
            'label' => 'Archaeological Data',
            'route' => 'site/add-triplestore',
            'params' => [
                'site-slug' => '__SITE_SLUG__' 
            ],
            'pages' => [
                [
                    'label' => 'Search',
                    'route' => 'site/add-triplestore/search',
                    'params' => [
                        'site-slug' => '__SITE_SLUG__' 
                    ],
                ],
                [
                    'label' => 'Add Excavation',
                    'route' => 'site/add-triplestore/upload',
                    'params' => [
                        'site-slug' => '__SITE_SLUG__', 
                        'query' => [
                            'upload_type' => 'excavation'
                        ]
                    ],
                ],
            ],
        ],
    ],
],
    'translator' => [
        'translation_file_patterns' => [
            [
                'type' => 'gettext',
                'base_dir' => __DIR__ . '/../language',
                'pattern' => '%s.mo',
                'text_domain' => null,
            ],
        ],
    ],
];