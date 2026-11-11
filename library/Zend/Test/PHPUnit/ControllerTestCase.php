<?php

/**
 * Zend Framework
 *
 * LICENSE
 *
 * This source file is subject to the new BSD license that is bundled
 * with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://framework.zend.com/license/new-bsd
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@zend.com so we can send you a copy immediately.
 *
 * @category   Zend
 * @package    Zend_Test
 * @copyright  Copyright (c) 2005-2015 Zend Technologies USA Inc. (http://www.zend.com)
 * @license    http://framework.zend.com/license/new-bsd     New BSD License
 * @version    $Id$
 */

use PHPUnit\Framework\ExpectationFailedException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Runner\Version;

/**
 * Functional testing scaffold for MVC applications
 *
 * @uses       PHPUnit_Framework_TestCase
 * @category   Zend
 * @package    Zend_Test
 * @subpackage PHPUnit
 * @copyright  Copyright (c) 2005-2015 Zend Technologies USA Inc. (http://www.zend.com)
 * @license    http://framework.zend.com/license/new-bsd     New BSD License
 * @version    $Id$
 */
abstract class Zend_Test_PHPUnit_ControllerTestCase extends TestCase
{
    /**
     * @var mixed Bootstrap file path or callback
     */
    public $bootstrap;

    /**
     * @var Zend_Controller_Front
     */
    protected $_frontController;

    /**
     * @var Zend_Dom_Query
     */
    protected $_query;

    /**
     * @var Zend_Controller_Request_Abstract
     */
    protected $_request;

    /**
     * @var Zend_Controller_Response_Abstract
     */
    protected $_response;

    /**
     * XPath namespaces
     * @var array
     */
    protected $_xpathNamespaces = [];

    /**
     * Overloading: prevent overloading to special properties
     *
     * @param  string $name
     * @param  mixed  $value
     * @throws Zend_Exception
     */
    public function __set($name, $value)
    {
        if (in_array($name, ['request', 'response', 'frontController'])) {
            require_once 'Zend/Exception.php';
            throw new Zend_Exception(sprintf('Setting %s object manually is not allowed', $name));
        }
        $this->$name = $value;
    }

    /**
     * Overloading for common properties
     *
     * Provides overloading for request, response, and frontController objects.
     *
     * @param  mixed $name
     * @return null|Zend_Controller_Front|Zend_Controller_Request_HttpTestCase|Zend_Controller_Response_HttpTestCase
     */
    public function __get($name)
    {
        switch ($name) {
            case 'request':
                return $this->getRequest();
            case 'response':
                return $this->getResponse();
            case 'frontController':
                return $this->getFrontController();
        }

        return null;
    }

    /**
     * Set up MVC app
     *
     * Calls {@link bootstrap()} by default
     */
    protected function setUp(): void
    {
        $this->bootstrap();
    }

    /**
     * Bootstrap the front controller
     *
     * Resets the front controller, and then bootstraps it.
     *
     * If {@link $bootstrap} is a callback, executes it; if it is a file, it include's
     * it. When done, sets the test case request and response objects into the
     * front controller.
     */
    final public function bootstrap(): void
    {
        $this->reset();
        if (null !== $this->bootstrap) {
            if ($this->bootstrap instanceof Zend_Application) {
                $this->bootstrap->bootstrap();
                $this->_frontController = $this->bootstrap->getBootstrap()->getResource('frontcontroller');
            } elseif (is_callable($this->bootstrap)) {
                call_user_func($this->bootstrap);
            } elseif (is_string($this->bootstrap)) {
                require_once 'Zend/Loader.php';
                if (Zend_Loader::isReadable($this->bootstrap)) {
                    include $this->bootstrap;
                }
            }
        }
        $this->frontController
             ->setRequest($this->getRequest())
             ->setResponse($this->getResponse());
    }

    /**
     * Dispatch the MVC
     *
     * If a URL is provided, sets it as the request URI in the request object.
     * Then sets test case request and response objects in front controller,
     * disables throwing exceptions, and disables returning the response.
     * Finally, dispatches the front controller.
     *
     * @param string|null $url
     */
    public function dispatch($url = null)
    {
        // redirector should not exit
        $redirector = Zend_Controller_Action_HelperBroker::getStaticHelper('redirector');
        $redirector->setExit(false);

        // json helper should not exit
        $json = Zend_Controller_Action_HelperBroker::getStaticHelper('json');
        $json->suppressExit = true;

        $request    = $this->getRequest();
        if (null !== $url) {
            $request->setRequestUri($url);
        }
        $request->setPathInfo(null);

        $controller = $this->getFrontController();
        $this->frontController
             ->setRequest($request)
             ->setResponse($this->getResponse())
             ->throwExceptions(false)
             ->returnResponse(false);

        if ($this->bootstrap instanceof Zend_Application) {
            $this->bootstrap->run();
        } else {
            $this->frontController->dispatch();
        }
    }

    /**
     * Reset MVC state
     *
     * Creates new request/response objects, resets the front controller
     * instance, and resets the action helper broker.
     *
     * @todo   Need to update Zend_Layout to add a resetInstance() method
     */
    public function reset()
    {
        $_SESSION = [];
        $_GET     = [];
        $_POST    = [];
        $_COOKIE  = [];
        $this->resetRequest();
        $this->resetResponse();
        Zend_Layout::resetMvcInstance();
        Zend_Controller_Action_HelperBroker::resetHelpers();
        $this->frontController->resetInstance();
        Zend_Session::$_unitTestEnabled = true;
    }

    /**
     * Rest all view placeholders
     */
    protected function _resetPlaceholders()
    {
        $registry = Zend_Registry::getInstance();
        $remove   = [];
        foreach ($registry as $key => $value) {
            if (strstr($key, '_View_')) {
                $remove[] = $key;
            }
        }

        foreach ($remove as $key) {
            unset($registry[$key]);
        }
    }

    /**
     * Reset the request object
     *
     * Useful for test cases that need to test multiple trips to the server.
     *
     * @return Zend_Test_PHPUnit_ControllerTestCase
     */
    public function resetRequest()
    {
        if ($this->_request instanceof Zend_Controller_Request_HttpTestCase) {
            $this->_request->clearQuery()
                           ->clearPost();
        }
        $this->_request = null;
        return $this;
    }

    /**
     * Reset the response object
     *
     * Useful for test cases that need to test multiple trips to the server.
     *
     * @return Zend_Test_PHPUnit_ControllerTestCase
     */
    public function resetResponse()
    {
        $this->_response = null;
        $this->_resetPlaceholders();
        return $this;
    }

    /**
     * Assert against DOM selection
     *
     * @param string $path CSS selector path
     * @param string $message
     */
    public function assertQuery($path, $message = '')
    {
        $this->_incrementAssertionCount();
        require_once 'Zend/Test/PHPUnit/Constraint/DomQuery.php';
        $constraint = new Zend_Test_PHPUnit_Constraint_DomQuery($path);
        $content    = $this->response->outputBody();
        if (!$constraint->evaluate($content, __FUNCTION__)) {
            $constraint->fail($path, $message);
        }
    }

    /**
     * Assert against DOM selection
     *
     * @param string $path CSS selector path
     * @param string $message
     */
    public function assertNotQuery($path, $message = '')
    {
        $this->_incrementAssertionCount();
        require_once 'Zend/Test/PHPUnit/Constraint/DomQuery.php';
        $constraint = new Zend_Test_PHPUnit_Constraint_DomQuery($path);
        $content    = $this->response->outputBody();
        if (!$constraint->evaluate($content, __FUNCTION__)) {
            $constraint->fail($path, $message);
        }
    }

    /**
     * Assert against DOM selection; node should contain content
     *
     * @param string $path CSS selector path
     * @param string $match content that should be contained in matched nodes
     * @param string $message
     */
    public function assertQueryContentContains($path, $match, $message = '')
    {
        $this->_incrementAssertionCount();
        require_once 'Zend/Test/PHPUnit/Constraint/DomQuery.php';
        $constraint = new Zend_Test_PHPUnit_Constraint_DomQuery($path);
        $content    = $this->response->outputBody();
        if (!$constraint->evaluate($content, __FUNCTION__, $match)) {
            $constraint->fail($path, $message);
        }
    }

    /**
     * Assert against DOM selection; node should NOT contain content
     *
     * @param string $path CSS selector path
     * @param string $match content that should NOT be contained in matched nodes
     * @param string $message
     */
    public function assertNotQueryContentContains($path, $match, $message = '')
    {
        $this->_incrementAssertionCount();
        require_once 'Zend/Test/PHPUnit/Constraint/DomQuery.php';
        $constraint = new Zend_Test_PHPUnit_Constraint_DomQuery($path);
        $content    = $this->response->outputBody();
        if (!$constraint->evaluate($content, __FUNCTION__, $match)) {
            $constraint->fail($path, $message);
        }
    }

    /**
     * Assert against DOM selection; node should match content
     *
     * @param string $path CSS selector path
     * @param string $pattern Pattern that should be contained in matched nodes
     * @param string $message
     */
    public function assertQueryContentRegex($path, $pattern, $message = '')
    {
        $this->_incrementAssertionCount();
        require_once 'Zend/Test/PHPUnit/Constraint/DomQuery.php';
        $constraint = new Zend_Test_PHPUnit_Constraint_DomQuery($path);
        $content    = $this->response->outputBody();
        if (!$constraint->evaluate($content, __FUNCTION__, $pattern)) {
            $constraint->fail($path, $message);
        }
    }

    /**
     * Assert against DOM selection; node should NOT match content
     *
     * @param string $path CSS selector path
     * @param string $pattern pattern that should NOT be contained in matched nodes
     * @param string $message
     */
    public function assertNotQueryContentRegex($path, $pattern, $message = '')
    {
        $this->_incrementAssertionCount();
        require_once 'Zend/Test/PHPUnit/Constraint/DomQuery.php';
        $constraint = new Zend_Test_PHPUnit_Constraint_DomQuery($path);
        $content    = $this->response->outputBody();
        if (!$constraint->evaluate($content, __FUNCTION__, $pattern)) {
            $constraint->fail($path, $message);
        }
    }

    /**
     * Assert against DOM selection; should contain exact number of nodes
     *
     * @param string $path CSS selector path
     * @param string $count Number of nodes that should match
     * @param string $message
     */
    public function assertQueryCount($path, $count, $message = '')
    {
        $this->_incrementAssertionCount();
        require_once 'Zend/Test/PHPUnit/Constraint/DomQuery.php';
        $constraint = new Zend_Test_PHPUnit_Constraint_DomQuery($path);
        $content    = $this->response->outputBody();
        if (!$constraint->evaluate($content, __FUNCTION__, $count)) {
            $constraint->fail($path, $message);
        }
    }

if (class_exists('PHPUnit\Runner\Version')) {
	$id = PHPUnit\Runner\Version::id();
} elseif (class_exists('PHPUnit_Runner_Version')) {
	$id = PHPUnit_Runner_Version::id();
} else {
	$id = '0.0.0';
}

if (version_compare($id, '6.0', '>=')) {
	include(dirname(__FILE__) . DIRECTORY_SEPARATOR . 'ControllerTestCase80.php');
	class Zend_Test_PHPUnit_ControllerTestCase extends Zend_Test_PHPUnit_ControllerTestCase80
	{}
} else {
	include(dirname(__FILE__) . DIRECTORY_SEPARATOR . 'ControllerTestCase34.php');
	class Zend_Test_PHPUnit_ControllerTestCase extends Zend_Test_PHPUnit_ControllerTestCase34
	{}
}
