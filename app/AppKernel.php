<?php

use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Config\Loader\LoaderInterface;

include_once __DIR__ . '/BaseKernel.php';

class AppKernel extends BaseKernel
{
    public function registerBundles()
    {
        return array_merge(parent::registerBundles(), array(
            // insert your front bundles here

        ));
    }
}