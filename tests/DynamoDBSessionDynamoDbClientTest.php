<?php

use PHPUnit\Framework\TestCase;
use Aws\CommandInterface;
use Aws\Result;
use Psr\Http\Message\RequestInterface;
use GuzzleHttp\Promise;

require_once(__DIR__ . '/../src/DynamoDbSessionHandler.php');


/**
 * Class DynamoDBSessionClientTest
 *
 * Test our standalone dependency-free dynamodb client
 */
class DynamoDBSessionDynamoDbClientTest extends TestCase
{
    private $config;

    public function __construct($name = null, array $data = [], $dataName = '')
    {
        parent::__construct($name, $data, $dataName);

        $file = '.env.testing';
        if (file_exists(__DIR__ . '/../' . $file)) {
            $dotenv = new \Dotenv\Dotenv(__DIR__ . '/../', $file);
            $dotenv->load();
        }

        $this->config = [
            'region' => getenv('AWS_REGION'),
            'version' => 'latest',
            'table_name' => getenv('SESSION_TABLE'),
            'debug' => getenv('SESSION_DEBUG') ? getenv('SESSION_DEBUG') : false
        ];

        if (getenv('DYNAMODB_ENDPOINT')) {
            $this->config['endpoint'] = getenv('DYNAMODB_ENDPOINT');
        }

        if (getenv('SESSION_AWS_ACCESS_KEY_ID')) {
            $this->config['credentials'] = [
                'key' => getenv('SESSION_AWS_ACCESS_KEY_ID'),
                'secret' => getenv('SESSION_AWS_SECRET_ACCESS_KEY')
            ];
        }

        $this->dynamoDbClient = new Idealstack\DynamoDbSessionsDependencyFree\DynamoDbSessionHandler(
            $this->config
        );
    }

    /**
     * Test the client works with temporary security credentials
     */
    public function testReadWriteWithTemporaryCredentials()
    {

        $myHandler = function (CommandInterface $cmd, RequestInterface $request) {
            $result = new Result(['Credentials' => [
                'AccessKeyId' => 'DUMMYCREDENTIALS',
                'SecretAccessKey' => 'DUMMYSECRETACCESSKEY',
                'SessionToken' => 'DUMMYTOKEN'
            ]]);
            return Promise\promise_for($result);
        };
        $stsClient = new \Aws\Sts\StsClient($this->config + ['handler' => $myHandler]);

        if (
            getenv('AWS_CONTAINER_CREDENTIALS_RELATIVE_URI') //This means we are already using temporary credentials, so skip this test
            || getenv('DYNAMODB_ENDPOINT')  // Temp credentials don't seem to work against the local endpoint 
        ) {

            $this->markTestSkipped();
            return;
        }

        $result = $stsClient->getSessionToken([
            'DurationSeconds' => 1800
        ])->toArray();


        $credentials = [
            'key' => $result['Credentials']['AccessKeyId'],
            'secret' => $result['Credentials']['SecretAccessKey'],
            'token' => $result['Credentials']['SessionToken']
        ];

        $config = $this->config + [
            'credentials' => $credentials,
        ];

        $dynamoDbClient = new Idealstack\DynamoDbSessionsDependencyFree\DynamoDbSessionHandler($config);

        $data = 'test' . rand(0, 10000000);
        $result = $dynamoDbClient->write('TEST', $data);
        $this->assertTrue($result);

        $result = $dynamoDbClient->read('TEST');
        $this->assertEquals($data, $result);
    }

    /**
     * Test reading and writing sessions works
     */
    public function testReadWrite()
    {
        $dynamoDbClient = $this->dynamoDbClient;

        $data = 'test' . rand(0, 10000000);
        $result = $dynamoDbClient->write('TEST', $data);
        $this->assertTrue($result);

        $result = $dynamoDbClient->read('TEST');
        $this->assertEquals($data, $result);
    }

    /**
     * Test destroying sessions works
     */
    public function testDestroy()
    {
        $dynamoDbClient = $this->dynamoDbClient;
        $data = 'test' . rand(0, 10000000);
        $id = 'testkey' . rand(0, 10000000);
        $result = $dynamoDbClient->write($id, $data);
        $this->assertTrue($result);

        $result = $dynamoDbClient->destroy($id);
        $this->assertTrue($result);

        $result = $dynamoDbClient->read($id);
        $this->assertEmpty($result);
    }

    /**
     * Test that performance is reasonable, and stress-test it a bit
     */
    public function testPerformance()
    {
        $table_name = getenv('SESSION_TABLE');

        // Test the performance of the official AWS SDK, as a baseline
        $i = 0;
        $start = microtime(true);
        while ($i++ < 20) {
            //Now compare with the performance of the SDK
            $dynamodb = new \Aws\DynamoDb\DynamoDbClient(
                $this->config
            );
            $dynamoDbClient = \Aws\DynamoDb\SessionHandler::fromClient(
                $dynamodb,
                $this->config
            );
            $data = 'test' . $i;
            $dynamoDbClient->write('TEST', $data);
            $result = $dynamoDbClient->read('TEST');
            $this->assertEquals($data, $result);
        }
        $end = microtime(true);
        $time_sdk = $end - $start;
        echo "Time to read and write 20 sessions in seconds (SDK): " . ($end - $start);


        //Test the performance of our session handler
        $i = 0;
        $start = microtime(true);
        while ($i++ < 20) {
            $dynamoDbClient = new Idealstack\DynamoDbSessionsDependencyFree\DynamoDbSessionHandler(
                $this->config
            );

            $data = 'test' . $i;
            $dynamoDbClient->write('TEST', $data);
            $result = $dynamoDbClient->read('TEST');
            $this->assertEquals($data, $result);
        }
        $end = microtime(true);
        echo "Time to read and write 20 sessions in seconds: (Dependency-Free)" . ($end - $start);
        $time_dependency_free = $end - $start;


        //Our client should be within 20% of the native client (typically we're a lot faster, but network variations etc
        $this->assertTrue(
            $time_dependency_free - $time_sdk < 0.2 * $time_sdk,
            "Our client is within  20% of the performance of the SDK - $time_dependency_free(Us) versus $time_sdk(SDK)"
        );
    }
}
