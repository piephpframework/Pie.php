<?php

namespace Pie\Modules\Route;

/**
 *
 * @author Ryan Naddy <untuned20@gmail.com>
 * @name RouteParams.php
 * @version 1.0.0 Aug 3, 2015
 */
class RouteParams{

    private $parameters = [];
    private $length = 0;

    public function __get($name){
        if(isset($this->parameters[$name])){
            return $this->parameters[$name];
        }elseif($name == 'length'){
            return count($this->parameters);
        }
        return '';
    }

    public function __set($name, $value){
        $this->parameters[$name] = $value;
    }

    /**
     * Gets a parameters value
     * @param string $name
     * @return string
     */
    public function getParameter($name, $default = null){
        if(isset($this->parameters[$name])){
            return $this->parameters[$name];
        }
        return $default;
    }

    /**
     * Gets a list of all the parameters
     * @return array
     */
    public function getParameters(){
        return $this->parameters;
    }

}
