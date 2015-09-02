<?php

namespace Object69\Core;

use Exception;
use ReflectionFunction;
use ReflectionMethod;

/**
 * @property App $parent The parent App
 */
class App{

    protected
        $name        = '',
        $apps        = [],
        $controllers = [],
        $directives  = [],
        $services    = [],
        $filters     = [],
        $parent      = null;

    public function __construct($name, array $dependencies){
        $this->name = $name;
        $apps       = [];

        foreach($dependencies as $dependName){
            $modules = glob(__DIR__ . '/../Modules/*', GLOB_ONLYDIR);
            foreach($modules as $module){
                $moduleName = basename($module);
                $app        = $this->loadModule($dependName, $moduleName, $module);
                if($app instanceof App){
                    $apps[$dependName] = $app;
                }
            }
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
        $this->apps = $apps;
    }

    protected function loadModule($dependName, $moduleName, $module){
        if(strtolower($dependName) == strtolower($moduleName)){
            /* @var $app App */
            $app = require_once $module . '/module.php';

            $app->service('rootScope', Object69::$rootScope);
            $app->service('env', new Env());
            $app->setParent($this);

            return $app;
        }
        return null;
    }

    public function __destruct(){
        foreach($this->apps as $name => $dep){
            $result = $dep->cleanup($this);
            if($result instanceof Event){
                $this->fireEvent($result);
            }
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
        $cbParams = $this->_getCbParams($callback);
        call_user_func_array($callback, $cbParams);
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
        if(is_callable($callback)){
            $this->controllers[$name]['controller'] = $callback;
        }elseif(is_string($callback)){
            $this->controllers[$name]['controller'] = new $callback();
            $this->controllers[$name]['method']     = $method;
        }else{
            throw new Exception('Invalid callback, must be a callable or a string');
        }
        $this->controllers[$name]['scope'] = new Scope();
        $this->controllers[$name]['call']  = null;
        return $this;
    }

    /**
     * Creates a service to be used within the app
     * @param string $name
     * @param mixed $object
     * @return App
     */
    public function service($name, $object){
        $this->services[$name] = $object;
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
        $cbParams                = $this->_getCbParams($object);
        $this->directives[$name] = call_user_func_array($call, $cbParams);
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
     * @return \Object69\Core\Call
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
                    if(!$controller['call']){
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

//        if(isset($this->controllers[$name])){
//            if(!$this->controllers[$name]['call']){
//                $call = $this->runController($this->controllers[$name]);
//            }else{
//                $call = $this->controllers[$name]['call'];
//            }
//            if($call instanceof Call){
//                return $call;
//            }
//        }
//        foreach($this->getApps() as $app){
//            $controllers = $app->getControllers();
//            if(isset($controllers[$name])){
//                if(!$controllers[$name]['call']){
//                    $call = $this->runController($controllers[$name]);
//                    return $call;
//                }elseif($controllers[$name]['call']){
//                    $call = $controllers[$name]['call'];
//                }
//            }
//        }
//        if(!isset($call)){
//            return new Call();
//        }
//        return $call;
    }

    public function exec($name){
        if(is_array($name)){
            $name = $name['controller'];
        }
        if(isset($this->controllers[$name])){
            $call = $this->runController($this->controllers[$name]);
        }else{
            $call = new Call();
        }
        return $call;
    }

    protected function runController($controller){
        if($controller){
            $scope              = null;
            $result             = $this->execController($controller, $scope);
            $controller['call'] = new Call($scope, $result);
            $call               = $controller['call'];
        }else{
            $call = null;
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
     * @param string $controller The controller
     * @param type $scope
     * @return type
     */
    public function execController($controller, &$scope = null){
        $cbParams = $this->_getCbParams($controller, $scope);
        $ctrl     = $controller['controller'];
        if(is_object($controller['controller']) && isset($controller['method'])){
            $method = $controller['method'];
            $result = call_user_func_array([$ctrl, $method], $cbParams);
        }else{
            $result = call_user_func_array($ctrl, $cbParams);
        }
        return $result;
    }

    protected function _getCbParams($item, &$scope = null){
        if(is_array($item)){
            $func  = $item['controller'];
            $scope = isset($item['scope']) ? $item['scope'] : null;
        }else{
            $func = $item;
        }
        if(is_object($func) && is_array($item) && isset($item['method'])){
            $rf = new ReflectionMethod($func, $item['method']);
        }else{
            $rf = new ReflectionFunction($func);
        }
        $params   = $rf->getParameters();
        $cbParams = [];
        foreach($params as $param){
            if($param->name == 'scope'){
                $cbParams[] = $scope;
            }elseif($param->name == 'rootScope'){
                $cbParams[] = Object69::$rootScope;
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
