<?php namespace Insanelab\Apidocs\Commands;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Config;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Http\Request;
use phpDocumentor\Reflection\DocBlock;
use ReflectionClass;

class ApiDocsGenerator
{

    /**
     * An array of all the registered routes.
     *
     * @var \Illuminate\Routing\RouteCollection
     */

    protected $routes;
    protected $router;

    protected $prefix;
    protected $dotPrefix;
    protected $storagePath;

    /**
     * The table headers for the command.
     *
     * @var array
     */
    protected $headers = array(
        'Domain', 'URI', 'Name', 'Action', 'Before Filters', 'After Filters'
    );

    /**
     * Create a new route command instance.
     *
     * @param  \Illuminate\Routing\Router $router
     * @return void
     */
    public function __construct(Router $router)
    {
        $this->storagePath = storage_path() . '/templates/apidocs';
        $this->router = $router;
        $this->routes = $router->getRoutes();
    }


    /**
     * Generates the API Documentation based upon a prefix
     *
     * @param  string $prefix
     * @return void
     */

    public function make($prefix, $parsedRoutes)
    {
        $this->prefix = $prefix;
        $this->dotPrefix = str_replace('/', '.', $this->prefix);

        $this->routes = $this->getRoutes();

        if (count($this->routes) == 0) {
            return;
        }

        $endpoints = $this->getEndpoints();

        $this->generateDirectoryStructure();
        $this->generateHTMLCode($endpoints, $parsedRoutes);

        return;
    }

    /**
     * Returns an array of endpoints
     *
     * @return array
     */

    protected function getEndpoints()
    {

        $endpoints = [];
        foreach ($this->routes as $route) {
            if ($route['action'] == "Closure") {

                // check for api/v1/docs
                if (strpos($route['uri'], $this->prefix . '/docs') !== false) {
                    continue;
                }
            }

            $array = explode("@", $route['action']);
            $class = $array[0];

            $reflector = new ReflectionClass($class);
            $docBlock = new DocBlock($reflector);

            // remove Controller
            $class = str_replace('Controller', '', $class);

            $endpoints["${class}"]['methods'] = [];
            $endpoints["${class}"]['description'] = $docBlock->getShortDescription();

        }
        return $this->getEndpointMethods($endpoints);
    }

    /**
     * Returns functions for the endpoints
     *
     * @param  $endpoints
     * @return array
     */

    protected function getEndpointMethods($endpoints)
    {

        foreach ($this->routes as $route) {

            if ($route['action'] == "Closure") {
                continue;
            }

            $array = explode("@", $route['action']);
            $class = $array[0];

            $methodName = (count($array) > 1) ? $array[1] : '';
            $endpointName = str_replace('Controller', '', $class);

            $reflector = new ReflectionClass($class);
            $docBlock = new DocBlock($reflector->getMethod($methodName));
            $controllerDocBlock = new DocBlock($reflector);

            $endpointNameCamelCase = $this->convertToSnakeCase($endpointName);
            $endpointNameCamelCasePlural = $this->convertToSnakeCase($endpointName) . 's';

            $route['uri'] = str_replace('{' . strtolower($endpointName) . '}', '{id}', $route['uri']);
            $route['uri'] = str_replace('{' . strtolower($endpointName) . 's}', '{id}', $route['uri']);
            $route['uri'] = str_replace('{' . strtolower($endpointNameCamelCase) . '}', '{id}', $route['uri']);
            $route['uri'] = str_replace('{' . strtolower($endpointNameCamelCasePlural) . '}', '{id}', $route['uri']);

            $route['function'] = $methodName;
            $route['docBlock'] = $docBlock;
            $route['controllerDocBlock'] = $controllerDocBlock;

            array_push($endpoints["${endpointName}"]['methods'], $route);
        }


        return $endpoints;
    }


    /**
     * Returns the path for the view based upon View Type
     *
     * @param  $viewType
     * @return string
     */

    protected function viewPathForType($viewType)
    {

        $docs = 'docs/';

        if ($viewType == 'docs') {
            $docs = '';
        }

        return base_path() . '/resources' . '/views/' . $viewType . '/' . $docs . $this->prefix . '/';
    }

    /**
     * Generates the HTML Code from the templates and saves them
     *
     * @param  $endpoints
     * @return void
     */

    protected function generateHTMLCode($endpoints, $parsedRoutes)
    {
        /*
        * Docs Index
        */
        $this->updatePrefixAndSaveTemplate('docs', Config::get('apidocs.index_template_path'));

        /*
        * Default Layout
        */

        $this->updatePrefixAndSaveTemplate('layouts', Config::get('apidocs.default_layout_template_path'));

        /*
        * Head
        */

        $this->updatePrefixAndSaveTemplate('includes', Config::get('apidocs.head_template_path'));

        /*
        * Introduction
        */

        $this->updatePrefixAndSaveTemplate('includes', Config::get('apidocs.introduction_template_path'));

        // let's generate the body
        $content = $this->createContentForTemplate($endpoints, $parsedRoutes);


        // Save the default layout
        $this->updateAndSaveDefaultLayoutTemplate($content);

    }

    /**
     * Copies template type from filepath to target
     *
     * @param  string $type , string $filepath
     * @return void
     */

    protected function copyAndSaveTemplate($type, $filepath)
    {
        $target = $this->viewPathForType($type) . basename($filepath);
        File::copy($filepath, $target);
    }

    /**
     *  Retrieves the content from the template and saves it to a new file
     *
     * @param  string $type , string $filepath
     * @return void
     */

    protected function updatePrefixAndSaveTemplate($type, $filepath)
    {

        $content = File::get($filepath);

        $content = str_replace('{prefix}', $this->dotPrefix, $content);
        $newPath = $this->viewPathForType($type) . basename($filepath);

        File::put($newPath, $content);

    }

    /**
     *  Saves the default layout with HTML content
     *
     * @param  string $content
     * @return void
     */

    protected function updateAndSaveDefaultLayoutTemplate($content)
    {

        $type = 'layouts';

        $path = Config::get('apidocs.default_layout_template_path');

        $file = File::get($path);
        $file = str_replace('{prefix}', $this->dotPrefix, $file);
        $file = str_replace('{navigation}', $content['navigation'], $file);
        $file = str_replace('{body-content}', $content['body-content'], $file);
        $logo_path = str_replace('{prefix}', $this->dotPrefix, Config::get('apidocs.logo_path'));
        $file = str_replace('{logo-path}', $logo_path, $file);
        $newPath = $this->viewPathForType($type) . basename($path);

        File::put($newPath, $file);
    }

    /**
     *  Generates the directory structure for the API documentation
     *
     * @param  string $content
     * @return void
     */

    protected function generateDirectoryStructure()
    {
        $docs_views_path = base_path() . '/resources/views/docs/' . $this->prefix;
        $docs_includes_path = base_path() . '/resources/views/includes/docs/' . $this->prefix;
        $docs_layouts_path = base_path() . '/resources/views/layouts/docs/' . $this->prefix;

        $paths = [$docs_views_path, $docs_includes_path, $docs_layouts_path];

        foreach ($paths as $path) {
            // delete current directory
            File::deleteDirectory($path, false);

            // create directory structure
            File::makeDirectory($path, $mode = 0777, true, true);
        }

        $this->generateAssetsDirectory();

    }

    /**
     *  Generates the assets directory
     *  by copying the files from the template directory to a public diretory
     *
     * @param  string $content
     * @return void
     */

    private function generateAssetsDirectory()
    {

        $destinationPath = public_path() . '/assets/docs/' . $this->dotPrefix;

        // create assets directory
        File::makeDirectory($destinationPath, $mode = 0777, true, true);

        $targetPath = Config::get('apidocs.assets_path');
        $directories = ['css', 'img', 'js'];

        foreach ($directories as $directory) {
            $target = $targetPath . '/' . $directory;
            $dest = $destinationPath . '/' . $directory;
            File::copyDirectory($target, $dest);
        }

    }

    /**
     *  Generates the content for the templates
     *
     * @param  array $endpoints
     * @return array|boolean
     */

    private function createContentForTemplate($endpoints = array(), $parsedRoutes)
    {
        if (!$endpoints) return FALSE;

        $navigation = '';
        $body = '';

        foreach ($endpoints as $endpoint_name => $array) {

            $sectionName = $this->normalizeSectionName($endpoint_name);

            $sectionItem = '';
            $sectionHead = '';
            $bodySection = '';
            $navSections = '';
            $navItems = '';

            $navSections .= File::get(config::get('apidocs.navigation_template_path'));
            $navSections = str_replace('{column-title}', $sectionName, $navSections);

            $sectionHead .= File::get(config::get('apidocs.section_header_template_path'));
            $sectionHead = str_replace('{column-name}', $sectionName, $sectionHead);
            $sectionHead = str_replace('{main-description}', $endpoints[$endpoint_name]['description'], $sectionHead);


            if (isset($array['methods'])) {

                foreach ($array['methods'] as $key => $value) {

                    $endpoint = $value;

                    $uri = explode(' ', $endpoint['uri']);

                    $navItems .= File::get(config::get('apidocs.nav_items_template_path'));
                    $navItems = str_replace('{column-title}', $sectionName, $navItems);
                    $navItems = str_replace('{function}', $endpoint['function'], $navItems);

                    $sectionItem .= File::get(config::get('apidocs.body_content_template_path'));
                    $sectionItem = str_replace('{column-name}', $sectionName, $sectionItem);
                    $sectionItem = str_replace('{request-type}', $endpoint['method'], $sectionItem);
                    $sectionItem = str_replace('{endpoint-short-description}', $endpoint['docBlock']->getShortDescription(), $sectionItem);
                    $sectionItem = str_replace('{endpoint-long-description}', $endpoint['docBlock']->getLongDescription(), $sectionItem);

                    $sectionItem = str_replace('{function}', $endpoint['function'], $sectionItem);
                    $sectionItem = str_replace('{request-uri}', end($uri), $sectionItem);

                    if ($value['jwt']) {
                        $sectionItem = str_replace('{authorization-ajax}', File::get(config::get('apidocs.body_authorization_template_path')), $sectionItem);
                    } else {
                        $sectionItem = str_replace('{authorization-ajax}', '', $sectionItem);
                    }

                    $method_params = $endpoint['docBlock']->getTagsByName('param');
                    $controller_params = $endpoint['controllerDocBlock']->getTagsByName('param');
                    $required_params = $endpoint['docBlock']->getTagsByName('required');
                    $auto_parameters = $this->findParsedRoute($parsedRoutes, end($uri), $endpoint['method']);

                    preg_match_all("/{+[a-zA-Z0-9]++}/", $endpoint['uri'], $route_parameters);

                    $required = [];
                    $required_route = [];
                    $optional = [];

                    if (isset($route_parameters[0]) AND $route_parameters[0]) {
                        foreach($route_parameters[0] as $param) {
                            $param = str_replace('{', '', $param);
                            $param = str_replace('}', '', $param);
                            $required_route[$param] = [
                                'required' => true,
                                'type' => 'integer',
                                'default' => '',
                                'value' => '',
                                'description' => 'GET param'
                            ];
                        }
                    }

                    foreach ($auto_parameters as $auto_key => $param) {
                        if (!isset($required[$auto_key]) AND !isset($optional[$auto_key]) AND !isset($required_route[$auto_key])) {
                            if (is_array($param['description'])) {
                                $param['description'] = '';
                            }
                            if ($param['required']) {
                                $required[$auto_key] = $param;
                            } else {
                                $optional[$auto_key] = $param;
                            }
                        }
                    }

                    $controller_params = array_merge($method_params, $controller_params);

                    foreach ($controller_params as $param) {
                        $param_name = str_replace($param->getDescription(), '', $param->getContent());
                        $param_name = str_replace($param->getType(), '', $param_name);
                        $param_type = $param->getType();
                        $param_content = $param->getDescription() ?: '';
                        $param_name = str_replace(' ', '', $param_name);
                        $param_name = urldecode($param_name);
                        if (isset($param_name[0]) && $param_name[0] == '$') {
                            $param_name = str_replace('$', '', $param_name);
                        }

                        if ($param_type == 'array' && strpos($param_name, '[') == false) {
                            $param_name .= '[]';
                        }

                        if (!isset($optional[$param_name]) AND !isset($required_route[$param_name])) {
                            $optional[$param_name] = [
                                'required' => false,
                                'type' => $param_type,
                                'default' => '',
                                'value' => '',
                                'description' => $param_content
                            ];
                        } elseif ($param_content) {
                            $optional[$param_name]['type'] = $param_type;
                            $optional[$param_name]['description'] = $param_content;
                        }
                    }

                    foreach ($required_params as $param) {
                        $param_name = $param->getContent();
                        $table = explode(' ', $param_name);
                        $param_type = $table[0];
                        $param_name = $table[1];
                        $param_content = isset($table[2]) ? $table[2] : '';
                        if (isset($param_name[0]) && $param_name[0] == '$') {
                            $param_name = str_replace('$', '', $param_name);
                        }
                        if (!isset($required[$param_name]) AND !isset($required_route[$param_name])) {
                            $required[$param_name] = [
                                'required' => true,
                                'type' => $param_type,
                                'default' => '',
                                'value' => '',
                                'description' => $param_content
                            ];
                        } elseif ($param_content) {
                            $required[$param_name]['type'] = $param_type;
                            $required[$param_name]['description'] = $param_content;
                        }
                    }

                    ksort($required_route);

                    ksort($required);

                    ksort($optional);

                    $params = array_merge($required_route, $required, $optional);

                    $parameters = '';

                    if ($params) {
                        foreach ($params as $param_name => $param) {
                            $param_name = urldecode($param_name);

                            $parameters .= File::get(config::get('apidocs.parameters_template_path'));

                            if ($param['required']) {
                                $parameters = str_replace('{param-name}', $param_name . ' <span style="color: red;">*</span>', $parameters);
                                $parameters = str_replace('name="' . $param_name . ' <span style="color: red;">*</span>"', 'name="' . $param_name . '"', $parameters);
                                $parameters = str_replace('{param-type}', $param['type'] . ' (required)', $parameters);
                            } else {
                                $parameters = str_replace('{param-name}', $param_name, $parameters);
                                $parameters = str_replace('{param-type}', $param['type'], $parameters);
                            }

                            $parameters = str_replace('{param-desc}', $param['description'], $parameters);

                            if (strpos(strtolower($param_name), 'password') !== false) {
                                if ($param['required']) {
                                    $parameters = str_replace('type="text" class="parameter-value-text" name="' . $param_name . '"', 'type="password" class="parameter-value-text" name="' . $param_name . '"', $parameters);
                                } else {
                                    $parameters = str_replace('type="text" class="parameter-value-text" name="' . $param_name . '"', 'type="password" class="parameter-value-text" name="' . $param_name . '"', $parameters);
                                }
                            }

                            if ($param['required']) {
                                $parameters = str_replace('class="parameter-value-text" name="' . $param_name . '"', 'class="parameter-value-text" name="' . $param_name . '" required', $parameters);
                            }

                        }
                    }

                    if (strlen($parameters) > 0) {
                        $sectionItem = str_replace('{request-parameters}', $parameters, $sectionItem); // insert the parameters into the section items
                    } else {

                        $sectionItem = str_replace('<h4>Parameters</h4>
                            <ul>
                              <li class="parameter-header">
                                <div class="parameter-name">PARAMETER</div>
                                <div class="parameter-type">TYPE</div>
                                <div class="parameter-desc">DESCRIPTION</div>
                                <div class="parameter-value">VALUE</div>
                              </li>
                              {request-parameters}
                            </ul>', '', $sectionItem);
                    }

                }

                $navSections = str_replace('{nav-items}', '<ul>' . $navItems . '</ul>', $navSections); // add the navigation items to the nav section

            } else {

                $navSections = str_replace('{nav-items}', '', $navSections); // add the navigation items to the nav section
            }

            $navigation .= $navSections;

            $bodySection .= File::get(config::get('apidocs.compile_content_template_path'));
            $bodySection = str_replace('{section-header}', $sectionHead, $bodySection);
            $bodySection = str_replace('{section-details}', $sectionItem, $bodySection);

            $body .= $bodySection;
        }

        $data = array(
            'navigation' => $navigation,
            'body-content' => $body
        );

        return $data;
    }

    /**
     *
     */
    private function findParsedRoute($parsedRoutes, $uri, $method)
    {
        $routeParams = $parsedRoutes['general']->where('uri', $uri)->filter(function ($value, $key) use ($method) {
            return in_array($method, $value['methods']);
        })->first();

        return $routeParams['parameters'];
    }

    /**
     * Retuns the last part of the section name
     *
     * @return string
     */
    protected function normalizeSectionName($name)
    {

        $sectionName = explode("\\", $name);
        $c = count($sectionName) - 1;
        if ($c < 0) $c = 0;
        $sectionName = $sectionName[$c];

        return $sectionName;
    }

    /**
     * Compile the routes into a displayable format.
     *
     * @return array
     */

    protected function getRoutes()
    {
        $results = array();

        foreach ($this->routes as $route) {
            $results[] = $this->getRouteInformation($route);
        }
        return array_filter($results);
    }

    /**
     * Get all of the pattern filters matching the route.
     *
     * @param  \Illuminate\Routing\Route $route
     * @return array
     */
    protected function getPatternFilters($route)
    {
        $patterns = array();

        foreach ($route->methods() as $method) {
            // For each method supported by the route we will need to gather up the patterned
            // filters for that method. We will then merge these in with the other filters
            // we have already gathered up then return them back out to these consumers.
            $inner = $this->getMethodPatterns($route->uri(), $method);

            $patterns = array_merge($patterns, array_keys($inner));
        }

        return $patterns;
    }

    /**
     * Get the route information for a given route.
     *
     * @param  string $name
     * @param  \Illuminate\Routing\Route $route
     * @return array|bool
     */
    protected function getRouteInformation(Route $route)
    {
        if ($route->getActionName() == 'Closure')
            return FALSE;

        $uri = implode('|', $route->methods()) . ' ' . $route->uri();

        $jwt = false;
        foreach ($route->middleware() as $middleware) {
            if (strpos($middleware, 'jwt.') !== false || strpos($middleware, 'auth') !== false) {
                $jwt = true;
            }
        }


        return $this->filterRoute(array(
            'host' => $route->domain(),
            'uri' => $uri,
            'name' => $route->getName(),
            'action' => $route->getActionName(),
            'jwt' => $jwt,
            'prefix' => $route->getPrefix(),
            'method' => $route->methods()[0]
        ));
    }

    /**
     * Filter the route by URI and / or name.
     *
     * @param  array $route
     * @return array|null
     */
    protected function filterRoute(array $route)
    {

        if (!str_contains($route['prefix'], $this->prefix)) {
            return null;
        }

        return $route;
    }

    /**
     * Get the pattern filters for a given URI and method.
     *
     * @param  string $uri
     * @param  string $method
     * @return array
     */
    protected function getMethodPatterns($uri, $method)
    {
        return $this->router->findPatternFilters(Request::create($uri, $method));
    }

    /**
     * Converts a CamelCase String to Snake Case
     *
     * @param  string $input
     * @return string
     */

    private function convertToSnakeCase($input)
    {
        preg_match_all('!([A-Z][A-Z0-9]*(?=$|[A-Z][a-z0-9])|[A-Za-z][a-z0-9]+)!', $input, $matches);
        $ret = $matches[0];
        foreach ($ret as &$match) {
            $match = $match == strtoupper($match) ? strtolower($match) : lcfirst($match);
        }
        return implode('_', $ret);
    }

}
