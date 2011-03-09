<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Service;

use Guzzle\Guzzle;
use Guzzle\Common\Collection;
use Guzzle\Common\Log\ClosureLogAdapter;
use Guzzle\Http\Plugin\LogPlugin;
use Guzzle\Service\ApiCommand;
use Guzzle\Service\Client;
use Guzzle\Service\Command\CommandSet;
use Guzzle\Service\Command\CommandInterface;
use Guzzle\Service\Command\ConcreteCommandFactory;
use Guzzle\Service\DescriptionBuilder\XmlDescriptionBuilder;
use Guzzle\Service\ServiceDescription;
use Guzzle\Tests\Service\Mock\Command\MockCommand;

/**
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class ClientTest extends \Guzzle\Tests\GuzzleTestCase
{
    protected $factory;
    protected $service;
    protected $serviceTest;
    protected $factoryTest;

    public function setUp()
    {
        $this->serviceTest = new ServiceDescription('test', 'Test service', 'http://www.test.com/', array(
            new ApiCommand(array(
                'name' => 'test_command',
                'doc' => 'documentationForCommand',
                'method' => 'DELETE',
                'can_batch' => true,
                'concrete_command_class' => 'Guzzle\\Tests\\Service\\Mock\\Command\\MockCommand',
                'args' => array(
                    'bucket' => array(
                        'required' => true
                    ),
                    'key' => array(
                        'required' => true
                    )
                )
            ))
        ), array(
            'foo' => array(
                'default' => 'bar',
                'required' => 'true'
            ),
            'base_url' => array(
                'required' => 'true'
            ),
            'api' => array(
                'required' => 'true'
            )
        ));

        $builder = new XmlDescriptionBuilder(__DIR__ . DIRECTORY_SEPARATOR . 'test_service.xml');
        $this->service = $builder->build();

        $this->factory = new ConcreteCommandFactory($this->service);
    }

    /**
     * Get a LogPlugin
     *
     * @return LogPlugin
     */
    private function getLogPlugin()
    {
        return new LogPlugin(new ClosureLogAdapter(
            function($message, $priority, $extras = null) {
                echo $message . ' ' . $priority . ' ' . $extras . "\n";
            }
        ));
    }

    /**
     * @covers Guzzle\Service\Client::getConfig
     */
    public function testGetConfig()
    {
        $client = new Client(new Collection(array(
            'base_url' => 'http://www.google.com/'
        )), $this->service, $this->factory);

        $this->assertEquals('http://www.google.com/', $client->getConfig('base_url'));

        $this->assertEquals(array(
            'base_url' => 'http://www.google.com/'
        ), $client->getConfig());
    }

    /**
     * @covers Guzzle\Service\Client::__construct
     * @expectedException Guzzle\Service\ServiceException
     */
    public function testConstructorValidatesConfig()
    {
        $client = new Client(false, $this->service, $this->factory);
    }

    /**
     * @covers Guzzle\Service\Client::__construct
     * @expectedException Guzzle\Service\ServiceException
     */
    public function testConstructorValidatesBaseUrlIsProvided()
    {
        $client = new Client(array(), new ServiceDescription('test', 'Test service', '', array(), array()), $this->factory);
    }

    /**
     * @covers Guzzle\Service\Client::__construct
     */
    public function testCanUseCollectionAsConfig()
    {
        $client = new Client(new Collection(array(
            'api' => 'v1',
            'key' => 'value',
            'base_url' => 'http://www.google.com/'
        )), $this->serviceTest, $this->factory);
        $this->assertEquals('v1', $client->getConfig('api'));
    }

    /**
     * @covers Guzzle\Service\Client
     */
    public function testInjectConfig()
    {
        $client = new Client(array(
            'api' => 'v1',
            'key' => 'value',
            'base_url' => 'http://www.google.com/'
        ), $this->serviceTest, $this->factory);

        $this->assertEquals('Testing...api/v1/key/value', $client->injectConfig('Testing...api/{{ api }}/key/{{ key }}'));

        // Make sure that the client properly validates and injects config
        $this->assertEquals('bar', $client->getConfig('foo'));

        try {
            $client = new Client(array(), $this->serviceTest, $this->factory);
            $this->fail('Did not throw exception when missing required arg');
        } catch (\Exception $e) {
            $this->assertContains('Requires that the api argument be supplied', $e->getMessage());
        }
    }

    /**
     * Test that a plugin can be attached to a client
     *
     * @covers Guzzle\Service\Client::__construct
     * @covers Guzzle\Service\Client::getRequest
     */
    public function testClientAttachersObserversToRequests()
    {
        $client = new Client(array(
            'base_url' => 'http://www.google.com/'
        ), $this->service, $this->factory);

        $logPlugin = $this->getLogPlugin();

        $client->getEventManager()->attach($logPlugin);

        // Make sure the plugin was registered correctly
        $this->assertTrue($client->getEventManager()->hasObserver($logPlugin));

        // Set a mock response on all requests generated by the Client
        $client->getEventManager()->attach(function($subject, $event, $context) {
            if ($event == 'request.create') {
                $context->setResponse(new \Guzzle\Http\Message\Response(200), true);
            }
        });

        // Get a request from the client and ensure the the observer was
        // attached to the new request
        $request = $client->getRequest('GET');
        $this->assertTrue($request->getEventManager()->hasObserver($logPlugin));

        // Make sure that the log plugin actually logged the request and response
        ob_start();
        $request->send();
        $logged = ob_get_clean();
        $this->assertEquals('www.google.com - "GET / HTTP/1.1" - 200 0 - 7 guzzle_request' . "\n", $logged);
    }

    /**
     * @covers Guzzle\Service\Client::getBaseUrl
     * @covers Guzzle\Service\Client::setBaseUrl
     */
    public function testClientReturnsValidBaseUrls()
    {
        $client = new Client(array(
            'base_url' => 'http://www.{{ foo }}.{{ data }}/',
            'data' => '123',
            'foo' => 'bar'
        ), $this->service, $this->factory);

        $this->assertEquals('http://www.bar.123/', $client->getBaseUrl());
        $client->setBaseUrl('http://www.google.com/');
        $this->assertEquals('http://www.google.com/', $client->getBaseUrl());
    }

    /**
     * @covers Guzzle\Service\Client::execute
     */
    public function testExecutesCommands()
    {
        $this->getServer()->flush();
        $this->getServer()->enqueue("HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n");

        $client = new Client(array('base_url' => $this->getServer()->getUrl()), $this->service, $this->factory);
        $cmd = new MockCommand();
        $client->execute($cmd);

        $this->assertInstanceOf('Guzzle\\Http\\Message\\Response', $cmd->getResponse());
        $this->assertInstanceOf('Guzzle\\Http\\Message\\Response', $cmd->getResult());
        $this->assertEquals(1, count($this->getServer()->getReceivedRequests(false)));
    }

    /**
     * @covers Guzzle\Service\Client::execute
     * @expectedException Guzzle\Service\Command\CommandSetException
     */
    public function testThrowsExceptionWhenExecutingMixedClientCommandSets()
    {
        $client = new Client(array('base_url' => 'http://www.test.com/'), $this->service, $this->factory);
        $otherClient = new Client(array('base_url' => 'http://www.test-123.com/'), $this->service, $this->factory);

        // Create a command set and a command
        $set = new CommandSet();
        $cmd = new MockCommand();
        $set->addCommand($cmd);

        // Associate the other client with the command
        $cmd->setClient($otherClient);

        // Send the set with the wrong client, causing an exception
        $client->execute($set);
    }

    /**
     * @covers Guzzle\Service\Client::execute
     * @expectedException Guzzle\Service\ServiceException
     */
    public function testThrowsExceptionWhenExecutingInvalidCommandSets()
    {
        $client = new Client(array('base_url' => 'http://www.test.com/'), $this->service, $this->factory);
        $client->execute(new \stdClass());
    }

    /**
     * @covers Guzzle\Service\Client::execute
     */
    public function testExecutesCommandSets()
    {
        $client = new Client(array('base_url' => 'http://www.test.com/'), $this->service, $this->factory);

        // Set a mock response for each request from the Client
        $client->getEventManager()->attach(function($subject, $event, $context) {
            if ($event == 'request.create') {
                $context->setResponse(new \Guzzle\Http\Message\Response(200), true);
            }
        });

        // Create a command set and a command
        $set = new \Guzzle\Service\Command\CommandSet();
        $cmd = new MockCommand();
        $set->addCommand($cmd);
        $this->assertSame($set, $client->execute($set));

        // Make sure it sent
        $this->assertTrue($cmd->isExecuted());
        $this->assertTrue($cmd->isPrepared());
        $this->assertEquals(200, $cmd->getResponse()->getStatusCode());
    }

    /**
     * @covers Guzzle\Service\Client::getCommand
     */
    public function testClientUsesCommandFactory()
    {
        $client = new Client(
            array('base_url' => 'http://www.test.com/', 'api' => 'v1'),
            $this->serviceTest,
            new ConcreteCommandFactory($this->serviceTest)
        );

        $this->assertInstanceOf('Guzzle\\Service\\Command\\CommandInterface', $client->getCommand('test_command', array(
            'bucket' => 'test',
            'key' => 'keyTest'
        )));
    }

    /**
     * @covers Guzzle\Service\Client::getService
     */
    public function testClientUsesService()
    {
        $client = new Client(
            array('base_url' => 'http://www.test.com/', 'api' => 'v1'),
            $this->serviceTest,
            new ConcreteCommandFactory($this->serviceTest)
        );

        $this->assertInstanceOf('Guzzle\\Service\\ServiceDescription', $client->getService());
        $this->assertSame($this->serviceTest, $client->getService());
    }

    /**
     * @covers Guzzle\Service\Client::setUserApplication
     * @covers Guzzle\Service\Client::getRequest
     */
    public function testSetsUserApplication()
    {
        $client = new Client(
            array('base_url' => 'http://www.test.com/', 'api' => 'v1'),
            $this->serviceTest,
            new ConcreteCommandFactory($this->serviceTest)
        );

        $this->assertSame($client, $client->setUserApplication('Test', '1.0Ab'));

        $request = $client->getRequest('GET');
        $this->assertEquals('Test/1.0Ab ' . Guzzle::getDefaultUserAgent(), $request->getHeader('User-Agent'));
    }

    /**
     * @covers Guzzle\Service\Client::getRequest
     */
    public function testClientAddsCurlOptionsToRequests()
    {
        $client = new Client(
            array(
                'base_url' => 'http://www.test.com/',
                'api' => 'v1',
                // Adds the option using the curl values
                'curl.CURLOPT_HTTPAUTH' => 'CURLAUTH_DIGEST',
                'curl.abc' => 'not added'
            ),
            $this->serviceTest,
            new ConcreteCommandFactory($this->serviceTest)
        );

        $request = $client->getRequest('GET');
        $options = $request->getCurlOptions();
        $this->assertEquals(CURLAUTH_DIGEST, $options->get(CURLOPT_HTTPAUTH));
        $this->assertNull($options->get('curl.abc'));
    }

    /**
     * @covers Guzzle\Service\Client::getRequest
     */
    public function testClientAddsCacheKeyFiltersToRequests()
    {
        $client = new Client(
            array(
                'base_url' => 'http://www.test.com/',
                'api' => 'v1',
                'cache.key_filter' => 'query=Date'
            ),
            $this->serviceTest,
            new ConcreteCommandFactory($this->serviceTest)
        );

        $request = $client->getRequest('GET');
        $this->assertEquals('query=Date', $request->getParams()->get('cache.key_filter'));
    }
}