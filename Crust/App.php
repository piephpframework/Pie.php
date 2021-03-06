<?php

namespace Pie\Crust;

use Pie\Pie;
use Closure;
use ReflectionFunction;
use ReflectionMethod;

/**
 * @property App $parent The parent App
 */
class App{

    protected
            $name        = '',
            $config      = null,
            $apps        = [],
            $controllers = [],
            $directives  = [],
            $services    = [],
            $events      = [],
            $filters     = [],
            $parent      = null;

    public function __construct($name){
        $this->name = $name;
    }

    public function addDepndencies(array $dependencies = []){
        $this->loadDependencies($dependencies);
    }

    public function loadDependencies($dependencies){
        $apps = [];
        foreach($dependencies as $dependName){
            // Load Pie.php modules
            $modules = glob(__DIR__ . '/../Modules/*', GLOB_ONLYDIR);
            foreach($modules as $module){
                $moduleName = basename($module);
                $app        = $this->loadModule($dependName, $moduleName, $module);
                if($app instanceof App){
                    $apps[$dependName] = $app;
                }
            }

            // Load Users modules
            $base        = isset($_ENV['root']['modules']) ? $_ENV['root']['modules'] : '.';
            $modulesBase = strpos($base, '/') === 0 ? $base : $_SERVER['DOCUMENT_ROOT'] . '/' . $base;
            $userModules = glob($modulesBase . '/*', GLOB_ONLYDIR);
            foreach($userModules as $module){
                $moduleName = basename($module);
                $app        = $this->loadModule($dependName, $moduleName, $module);
                if($app instanceof App){
                    $apps[$dependName] = $app;
                }
            }
        }
        $this->apps = array_merge($this->apps, $apps);
    }

    protected function loadModule($dependName, $moduleName, $module){
        if(strtolower($dependName) == strtolower($moduleName)){
            /* @var $app App */
            $app = require_once $module . '/module.php';

            // $app->service('rootScope', Pie::$rootScope);
            // $app->service('env', new Env());
            $app->setParent($this);

            return $app;
        }
        return null;
    }

    public function get($name){
        foreach($this->apps as $app_name => $app){
            if($app_name == $name){
                return $app;
            }
        }
        $parent = $this->getParent();
        if($parent !== null){
            return $parent->get($name);
        }
        $modules = glob(__DIR__ . '/../Modules/*', GLOB_ONLYDIR);
        foreach($modules as $module){
            $moduleName = basename($module);
            $app = $this->loadModule($name, $moduleName, $module);
            if($app !== null){
                if($app instanceof App){
                    $ths->apps[$name] = $app;
                }
                return $app;
            }
        }
    }

    public function __destruct(){
        // Run the config
        if($this->config instanceof Closure){
            $cbParams = $this->_getCbParams($this->config);
            call_user_func_array($this->config, $cbParams);
        }

        // run additional events
        if($this->getParent() === null){
            // Cleanup final code call
            $this->broadcast('cleanup', [$this]);
        }
    }

    public function __call($name, $arguments){
        if(isset($this->$name) && is_callable($this->$name)){
            $call = $this->$name->bindTo($this, $this);
            return call_user_func_array($call, $arguments);
        }
    }

    public function setParent($parent){
        $this->parent = $parent;
    }

    public function getParent(){
        return $this->parent;
    }

    public function controllerExists($controllerName, &$controller = null){
        $curParent = $this;
        while($curParent != null){
            foreach($this->controllers as $name => $contrl){
                if($name == $controllerName){
                    $controller = $contrl;
                    return true;
                }
            }
            $curParent = $this->getParent();
            if($curParent != null){
                return $curParent->controllerExists($controllerName, $controller);
            }
        }
        return false;
    }

    /**
     * Evaluates Pie expressions<br>
     * <b>Warning:<b> Do not Evaluate user input!
     * @param string $eval The string to be evaluated
     * @param Scope $scope The scope to test evaluations
     */
    public function evaluate($eval, Scope $scope = null, $repeater = ''){
        $toEval = preg_replace_callback("/(?<=').+?(?=')/s", function($matches) use ($scope, $repeater){
            $find = preg_replace('/^' . $repeater . '\./', '', $matches[0]);
            $find = Pie::findRecursive($find, $scope);
            return $find !== '' ? $find : $matches[0];
        }, $eval);

        if(empty($toEval)){
            return false;
        }
        $isValid = false;
        eval("\$isValid = ($toEval);");

        return (bool)$isValid;
    }

    /**
     * Creates an event listener to listen for an event.
     * Modules can have more than one event with the same name
     * @param string $name The name of the event
     * @param Closure $callback The event to be executed
     */
    public function listen($name, callable $callback){
        if($callback instanceof Closure){
            $callback = $callback->bindTo($this, $this);
            $this->events[$name][] = [
                'event'  => $callback,
                'called' => false
            ];
        }
        return $this;
    }

    /**
     * Broadcasts an event to all the listeners listening
     * @param string $name The event to run
     * @param array $array The list of arguments to pass to the event
     * @param int $count The number of listeners executed
     */
    public function broadcast($name, array $args = [], &$count = 0){
        // Run all events within the current module
        foreach($this->apps as $app){
            if(isset($app->events[$name])){
                foreach($app->events[$name] as $event){
                    if($event['event'] instanceof Closure){
                        $evt = new ReflectionFunction($event['event']);
                        $argCount = count($args);
                        if($argCount >= $evt->getNumberOfRequiredParameters() && $argCount <= $evt->getNumberOfParameters()){
                            call_user_func_array($event['event'], $args);
                            $event['called'] = true;
                            $count++;
                        }
                    }
                }
            }
        }

        // Move to the parent and run those listeners
        $parent = $this->getParent();
        if($parent !== null){
            $parent->broadcast($name, $args, $count);
        }else{
            if(isset($this->events[$name])){
                foreach($this->events[$name] as $event){
                    if($event['event'] instanceof Closure){
                        $evt = new ReflectionFunction($event['event']);
                        $argCount = count($args);
                        if($argCount >= $evt->getNumberOfRequiredParameters() && $argCount <= $evt->getNumberOfParameters()){
                            call_user_func_array($event['event'], $args);
                            $event['called'] = true;
                            $count++;
                        }
                    }
                }
            }
        }
        return $this;
    }

    /**
     * Fires off an event to listening dependencies
     * @param Event $event
     */
    public function fireEvent(Event $event){
        foreach($this->apps as $dep){
            if(isset($dep->{$event->name}) && is_callable($dep->{$event->name})){
                $call = $dep->{$event->name}->bindTo($dep, $dep);
                call_user_func_array($call, [$event->value, $this]);
            }
            $parent = $dep->getParent();
            if($parent !== null){
                $parent->fireEvent($event);
            }
        }
    }

    /**
     * Gets the name of the app
     * @return type
     */
    public function getName(){
        return $this->name;
    }

    /**
     * Executes the configuration
     * @param callable $callback
     * @return App
     */
    public function config(callable $callback){
        $call         = $callback->bindTo($this, $this);
        $this->config = $call;
        // $cbParams = $this->_getCbParams($callback);
        // call_user_func_array($call, $cbParams);
        return $this;
    }

    /**
     * Creates a controller to be used within the app
     * @param string $name
     * @param callable|string $callback
     * @param string $method
     * @return App
     */
    public function controller($name, $callback, $method = null){
        $this->controllers[$name] = new Controller($name, $callback, $method);
        $this->controllers[$name]->setScope(new Scope());
        return $this->controllers[$name];
    }

    /**
     * Creates a service to be used within the app
     * @param string $name
     * @param mixed $object
     * @return App
     */
    public function service($name, $object){
        if(is_callable($object)){
            $cbParams              = $this->_getCbParams($object);
            $this->services[$name] = call_user_func_array($object, $cbParams);
        }else{
            $this->services[$name] = $object;
        }
        return $this;
    }

    /**
     * Creates a directive to be used within the app
     * @param string $name
     * @param mixed $object
     * @return App
     */
    public function directive($name, $object){
        $call                    = $object->bindTo($this, $this);
        $this->directives[$name] = $call;
        return $this;
    }

    /**
     * Creates a filter that can be used in the template tool
     * @param string $name
     * @param mixed $object
     * @return App
     */
    public function filter($name, $object){
        $call                 = $object->bindTo($this, $this);
        $cbParams             = $this->_getCbParams($object);
        $this->filters[$name] = call_user_func_array($call, $cbParams);
        return $this;
    }

    /**
     * Calls a function
     * @param type $name
     * @return Call
     */
    public function call($name, $parent = null){
        $current = $parent === null ? $this : $parent;
        foreach($current->getControllers() as $ctrlName => $controller){
            if($ctrlName == $name){
                return $current->runController($controller);
            }
        }
        foreach($current->getApps() as $app){
            foreach($app->getControllers() as $ctrlName => $controller){
                if($ctrlName == $name){
                    if(($controller instanceof Controller && !$controller->call) || !$controller['call']){
                        return $app->runController($controller);
                    }else{
                        return $controller['call'];
                    }
                }
            }
        }

        if($current->parent !== null){
            return $current->call($name, $current->getParent());
        }
        return new Call();
    }

    public function exec($controller){
        if($controller instanceof Controller){
            $call = $this->runController($controller);
        }else{
            $call = new Call();
        }
        return $call;
    }

    /**
     * Runs a controller
     * @param Controller $controller
     * @return Call
     */
    protected function runController(Controller $controller){
        $call = null;
        if($controller){
            $scope  = null;
            $result = $this->execController($controller, $scope);
            $controller->setCall($call   = new Call($scope, $result));
        }
        return $call;
    }

    /**
     * Gets a list of the applications classes
     * @return array
     */
    public function getClasses(){
        return $this->classes;
    }

    public function getControllers(){
        return $this->controllers;
    }

    public function getServices(){
        return $this->services;
    }

    public function getDirectives(){
        return $this->directives;
    }

    public function getFilters(){
        return $this->filters;
    }

    public function getApps(){
        return $this->apps;
    }

    /**
     * Runs a particular controller
     * @param Controller $controller The controller
     * @param type $scope
     * @return type
     */
    public function execController(Controller $controller, &$scope = null){
        $cbParams = $this->_getCbParams($controller, $scope);
        if($controller->method !== null){
            $result = call_user_func_array([$controller->controller, $controller->method], $cbParams);
        }else{
            $result = call_user_func_array($controller->controller, $cbParams);
        }
        return $result;
    }

    public function getCallbackArgs($controller, &$scope = null){
        return $this->_getCbParams($controller, $scope);
    }

    protected function _getCbParams($controller, &$scope = null){
        if(is_array($controller)){
            $func  = $controller['controller'];
            $scope = $controller['controller']->getScope(); //isset($controller['scope']) ? $controller['scope'] : null;
        }else{
            $func = $controller;
            if($func instanceof Controller){
                $scope = $func->getScope();
            }
        }

        if($func instanceof Controller && $func->method !== null){
            $rf = new ReflectionMethod($func->controller, $func->method);
        }elseif($func instanceof Controller && $func->method === null){
            $rf = new \ReflectionFunction($func->controller);
        }else{
            $rf = new ReflectionFunction($func);
        }
        $params   = $rf->getParameters();
        $cbParams = [];
        foreach($params as $param){
            if($param->name == 'scope'){
                $cbParams[] = $scope;
            }elseif($param->name == 'rootScope'){
                $cbParams[] = Pie::$rootScope;
            }else{
                $cbParams[] = $this->paramLookup($param->name);
            }
        }
        return $cbParams;
    }

    protected function paramLookup($pname, $parent = null){
        /* @var $current App */
        $current = $parent === null ? $this : $parent;
        // Inject Services From Current App
        foreach($current->getServices() as $serviceName => $service){
            if($pname == $serviceName){
                return $service;
            }
        }
        /* @var $depend App */
        foreach($current->getApps() as $depend){
            // Inject Registered Services
            $services = $depend->getServices();
            foreach($services as $serviceName => $service){
                if($pname == $serviceName){
                    return $service;
                }
            }
        }
        if($current->parent !== null){
            return $this->paramLookup($pname, $current->parent);
        }
        return null;
    }

}
