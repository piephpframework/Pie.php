<?php

use Pie\Modules\Session\Session;
use Pie\Pie;

return call_user_func(function(){
    $app = Pie::module('Session', []);

    $app->service('session', new Session());

    return $app;
});
