<?php
/**
 * @license   http://opensource.org/licenses/BSD-3-Clause BSD-3-Clause
 * @copyright Copyright (c) 2014 Zend Technologies USA Inc. (http://www.zend.com)
 */

namespace ZF\Apigility\Documentation\Swagger;

use Zend\Http\Request;
use ZF\Apigility\Documentation\Api as BaseApi;
use ZF\Apigility\Documentation\Operation;

class Api extends BaseApi
{
    /**
     * @var BaseApi
     */
    protected $api;

    /**
     * @param BaseApi $api
     */
    public function __construct(BaseApi $api)
    {
        $this->api = $api;
    }

    protected function operationToArray(
        Operation $operation,
        \ZF\Apigility\Documentation\Service $service,
        $collection = true
    ) {
        $parameters = [];
        $consumes   = [];

        switch ($operation->getHttpMethod()) {
            case Request::METHOD_GET:
                $fields = $service->getFields('input_filter');

                foreach ($fields as $field) {
                    $parameters[] = [
                        'name' => $field->getName(),
                        'description' => $field->getDescription(),
                        'required' => $field->isRequired(),
                    ];
                }
                break;

            case Request::METHOD_POST:
            case Request::METHOD_PUT:
            case Request::METHOD_PATCH:
                $parameters = [
                    [
                        'in'          => 'body',
                        'name'        => 'body',
                        'description' => '',
                        'required'    => true,
                        'schema'      => [
                            '$ref' => sprintf(
                                '#/definitions/%s',
                                $service->getName()
                            ),
                        ],
                    ],
                ];

                $consumes = $service->getRequestAcceptTypes();
                break;
        }

        $responses = [];
        foreach ($operation->getResponseStatusCodes() as $responseStatusCode) {
            $responses[$responseStatusCode['code']] = [
                'description' => $responseStatusCode['message']
            ];

            if ($responseStatusCode['code'] == 200) {
                switch ($operation->getHttpMethod()) {
                    case Request::METHOD_GET:
                        if ($collection) {
                            $responses[$responseStatusCode['code']]['schema']
                                = [
                                'type'  => 'array',
                                'items' => [
                                    '$ref' => sprintf(
                                        '#/definitions/%s',
                                        $service->getName()
                                    ),
                                ],
                                ];
                        } else {
                            $responses[$responseStatusCode['code']]['schema']
                                = [
                                '$ref' => sprintf(
                                    '#/definitions/%s',
                                    $service->getName()
                                ),
                                ];
                        }
                        break;

                    case Request::METHOD_DELETE:
                        $responses[$responseStatusCode['code']]['schema']
                            = ['$ref' => sprintf('#/definitions/ApiResponse'),];
                        break;
                }
            }
        }

        return [
            'tags'        => [
                $service->getName(),
            ],
            'summary'     => $operation->getDescription(),
            'description' => '',
            'operationId' => sprintf(
                '%s%s',
                strtolower($operation->getHttpMethod()),
                $service->getName()
            ),
            'consumes'    => $consumes,
            'produces'    => $service->getRequestContentTypes(),
            'parameters'  => $parameters,
            'responses'   => $responses,
            //                        'security'    => [
            //                            [
            //                                'petstore_auth' => [
            //                                    0 => 'write:pets',
            //                                    1 => 'read:pets',
            //                                ],
            //                            ],
            //                        ],
        ];
    }

    /**
     * @return array
     */
    public function toArray()
    {
        $tags        = [];
        $paths       = [];
        $definitions = [];

        foreach ($this->api->services as $service) {
            // routes and parameter mangling ([:foo] will become {foo}
            $routeBasePath         = substr(
                $service->getRoute(),
                0,
                strpos($service->getRoute(), '[')
            );
            $routeWithReplacements = str_replace(
                ['[', ']', '{/', '{:'],
                ['{', '}', '/{', '{'],
                $service->getRoute()
            );

            // find all parameters in Swagger naming format
            preg_match_all(
                '#{([\w\d_-]+)}#',
                $routeWithReplacements,
                $parameterMatches
            );

            $tags[] = [
                'name'        => $service->getName(),
                'description' => ! empty($service->getDescription())
                    ? $service->getDescription() : '',
                /*
                'externalDocs' =>
                    [
                        'description' => 'Find out more',
                        'url'         => 'http://swagger.io',
                    ],
                */
            ];

            if (! empty($service->getOperations())) {
                $route = str_replace(
                    '/{' . $service->getRouteIdentifierName() . '}',
                    '',
                    $routeWithReplacements
                );

                foreach ($service->getOperations() as $operation) {
                    $paths[$route][strtolower(
                        $operation->getHttpMethod()
                    )]
                        = $this->operationToArray($operation, $service);
                }
            }

            if (! empty($service->getEntityOperations())) {
                foreach ($service->getEntityOperations() as $operation) {
                    $paths[$routeWithReplacements][strtolower(
                        $operation->getHttpMethod()
                    )]
                        = $this->operationToArray($operation, $service, false);
                }
            }

            $fields = $service->getFields('input_filter');

            $requiredProperties = $properties = [];
            foreach ($fields as $field) {
                if ($field->getFieldType() == 'object') {
                    $properties[$field->getName()] = [
                        '$ref' => sprintf(
                            '#/definitions/%s',
                            ucfirst($field->getName())
                        ),
                    ];
                } else {
                    $properties[$field->getName()] = [
                        'type'        => ! empty($field->getFieldType()) ? $field->getFieldType() : 'string',
                        //'dataType'    => method_exists($field, 'getFieldType') ? $field->getFieldType() : 'string',
                        //                    'format'      => '',
                        //                    'enum'        => [],
                        'description' => $field->getDescription(),
                    ];

                    if (! empty($field->getExample())) {
                        $properties[$field->getName()]['example'] = $field->getExample();
                    }
                }

                if ($field->isRequired()) {
                    $requiredProperties[] = $field->getName();
                }
            }

            $definitions[$service->getName()] = [
                'type'       => 'object',
                'required'   => $requiredProperties,
                'properties' => $properties,
            ];
        }

        $definitions['ApiResponse'] = [
            'type'       => 'object',
            'properties' => [
                'code'    => [
                    'type'   => 'integer',
                    'format' => 'int32',
                ],
                'type'    => [
                    'type' => 'string',
                ],
                'message' => [
                    'type' => 'string',
                ],
            ],
        ];

        return [
            'swagger'     => '2.0',
            'paths'       => $paths,
            'definitions' => $definitions,
            /*
            'info'                => [
                'description'    => 'This is a sample server Petstore server.  You can find out more about Swagger at [http://swagger.io](http://swagger.io) or on [irc.freenode.net, #swagger](http://swagger.io/irc/).  For this sample, you can use the api key `special-key` to test the authorization filters.',
                'version'        => '1.0.0',
                'title'          => 'Swagger Petstore',
                'termsOfService' => 'http://swagger.io/terms/',
                'contact'        => [
                    'email' => 'apiteam@swagger.io',
                ],
                'license'        => [
                    'name' => 'Apache 2.0',
                    'url'  => 'http://www.apache.org/licenses/LICENSE-2.0.html',
                ],
            ],
            'host'                => 'petstore.swagger.io',
            'basePath'            => '/v2',
            'schemes'             =>
                [
                    0 => 'http',
                ],
            'securityDefinitions' =>
                [
                    'petstore_auth' =>
                        [
                            'type'             => 'oauth2',
                            'authorizationUrl' => 'http://petstore.swagger.io/oauth/dialog',
                            'flow'             => 'implicit',
                            'scopes'           =>
                                [
                                    'write:pets' => 'modify pets in your account',
                                    'read:pets'  => 'read your pets',
                                ],
                        ],
                    'api_key'       =>
                        [
                            'type' => 'apiKey',
                            'name' => 'api_key',
                            'in'   => 'header',
                        ],
                ],
            'definitions'         =>
                [
                    'Order'       =>
                        [
                            'type'       => 'object',
                            'properties' =>
                                [
                                    'id'       =>
                                        [
                                            'type'   => 'integer',
                                            'format' => 'int64',
                                        ],
                                    'petId'    =>
                                        [
                                            'type'   => 'integer',
                                            'format' => 'int64',
                                        ],
                                    'quantity' =>
                                        [
                                            'type'   => 'integer',
                                            'format' => 'int32',
                                        ],
                                    'shipDate' =>
                                        [
                                            'type'   => 'string',
                                            'format' => 'date-time',
                                        ],
                                    'status'   =>
                                        [
                                            'type'        => 'string',
                                            'description' => 'Order Status',
                                            'enum'        =>
                                                [
                                                    0 => 'placed',
                                                    1 => 'approved',
                                                    2 => 'delivered',
                                                ],
                                        ],
                                    'complete' =>
                                        [
                                            'type'    => 'boolean',
                                            'default' => false,
                                        ],
                                ],
                            'xml'        =>
                                [
                                    'name' => 'Order',
                                ],
                        ],
                    'User'        =>
                        [
                            'type'       => 'object',
                            'properties' =>
                                [
                                    'id'         =>
                                        [
                                            'type'   => 'integer',
                                            'format' => 'int64',
                                        ],
                                    'username'   =>
                                        [
                                            'type' => 'string',
                                        ],
                                    'firstName'  =>
                                        [
                                            'type' => 'string',
                                        ],
                                    'lastName'   =>
                                        [
                                            'type' => 'string',
                                        ],
                                    'email'      =>
                                        [
                                            'type' => 'string',
                                        ],
                                    'password'   =>
                                        [
                                            'type' => 'string',
                                        ],
                                    'phone'      =>
                                        [
                                            'type' => 'string',
                                        ],
                                    'userStatus' =>
                                        [
                                            'type'        => 'integer',
                                            'format'      => 'int32',
                                            'description' => 'User Status',
                                        ],
                                ],
                            'xml'        =>
                                [
                                    'name' => 'User',
                                ],
                        ],
                    'Category'    =>
                        [
                            'type'       => 'object',
                            'properties' =>
                                [
                                    'id'   =>
                                        [
                                            'type'   => 'integer',
                                            'format' => 'int64',
                                        ],
                                    'name' =>
                                        [
                                            'type' => 'string',
                                        ],
                                ],
                            'xml'        =>
                                [
                                    'name' => 'Category',
                                ],
                        ],
                    'Tag'         =>
                        [
                            'type'       => 'object',
                            'properties' =>
                                [
                                    'id'   =>
                                        [
                                            'type'   => 'integer',
                                            'format' => 'int64',
                                        ],
                                    'name' =>
                                        [
                                            'type' => 'string',
                                        ],
                                ],
                            'xml'        =>
                                [
                                    'name' => 'Tag',
                                ],
                        ],
                    'Pet'         =>
                        [
                            'type'       => 'object',
                            'required'   =>
                                [
                                    0 => 'name',
                                    1 => 'photoUrls',
                                ],
                            'properties' =>
                                [
                                    'id'        =>
                                        [
                                            'type'   => 'integer',
                                            'format' => 'int64',
                                        ],
                                    'category'  =>
                                        [
                                            '$ref' => '#/definitions/Category',
                                        ],
                                    'name'      =>
                                        [
                                            'type'    => 'string',
                                            'example' => 'doggie',
                                        ],
                                    'photoUrls' =>
                                        [
                                            'type'  => 'array',
                                            'xml'   =>
                                                [
                                                    'name'    => 'photoUrl',
                                                    'wrapped' => true,
                                                ],
                                            'items' =>
                                                [
                                                    'type' => 'string',
                                                ],
                                        ],
                                    'tags'      =>
                                        [
                                            'type'  => 'array',
                                            'xml'   =>
                                                [
                                                    'name'    => 'tag',
                                                    'wrapped' => true,
                                                ],
                                            'items' =>
                                                [
                                                    '$ref' => '#/definitions/Tag',
                                                ],
                                        ],
                                    'status'    =>
                                        [
                                            'type'        => 'string',
                                            'description' => 'pet status in the store',
                                            'enum'        =>
                                                [
                                                    0 => 'available',
                                                    1 => 'pending',
                                                    2 => 'sold',
                                                ],
                                        ],
                                ],
                            'xml'        =>
                                [
                                    'name' => 'Pet',
                                ],
                        ],
                    'ApiResponse' =>
                        [
                            'type'       => 'object',
                            'properties' =>
                                [
                                    'code'    =>
                                        [
                                            'type'   => 'integer',
                                            'format' => 'int32',
                                        ],
                                    'type'    =>
                                        [
                                            'type' => 'string',
                                        ],
                                    'message' =>
                                        [
                                            'type' => 'string',
                                        ],
                                ],
                        ],
                ],
            'externalDocs'        =>
                [
                    'description' => 'Find out more about Swagger',
                    'url'         => 'http://swagger.io',
                ],
            */
            /*
            'apiVersion' => $this->api->version,
            'basePath' => '/api',
            'resourcePath' => '/' . $this->api->name,
            'apis' => $services,
            */
        ];
    }
}
