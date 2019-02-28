<?php

use PHPUnit\Framework\TestCase;

require_once(__DIR__ . '/../src/DynamoDbSessionHandler.php');


/**
 * Class DynamoDBSessionClientTest
 *
 * Test our standalone dependency-free dynamodb client
 */
class DynamoDBSessionDynamoDbClientTest extends TestCase
{
    private $credentials;

    public function __construct($name = null, array $data = [], $dataName = '')
    {
        parent::__construct($name, $data, $dataName);

        $file = '.env.testing';
        if (file_exists(__DIR__ . '/../' . $file)) {
            $dotenv = new \Dotenv\Dotenv(__DIR__ . '/../', $file);
            $dotenv->load();
        }

        $this->credentials = [
            'region' => getenv('AWS_REGION'),
            'version' => 'latest',
        ];

        if (getenv('SESSION_AWS_ACCESS_KEY_ID')) {
            $this->credentials['credentials'] = [
                'key' => getenv('SESSION_AWS_ACCESS_KEY_ID'),
                'secret' => getenv('SESSION_AWS_SECRET_ACCESS_KEY')
            ];
        }

        $this->dynamoDbClient = new Idealstack\DynamoDbSessionsDependencyFree\DynamoDbSessionHandler(
            $this->credentials +
            [
                'table_name' => getenv('SESSION_TABLE'),
                'debug' => getenv('SESSION_DEBUG') ? getenv('SESSION_DEBUG') : false
            ]
        );

    }

    /**
     * Test the client works with temporary security credentials
     */
    public function testReadWriteWithTemporaryCredentials()
    {
        $stsClient = new \Aws\Sts\StsClient($this->credentials);

        if (getenv('AWS_CONTAINER_CREDENTIALS_RELATIVE_URI')) {
            //This means we are already using temporary credentials, so skip this test
            $this->markTestSkipped();
            return;
        }

        $result = $stsClient->getSessionToken([
            'DurationSeconds' => 1800
        ])->toArray();

        $dynamoDbClient = new Idealstack\DynamoDbSessionsDependencyFree\DynamoDbSessionHandler([
            'region' => getenv('AWS_REGION'),
            'version' => 'latest',
            'credentials' => [
                'key' => $result['Credentials']['AccessKeyId'],
                'secret' => $result['Credentials']['SecretAccessKey'],
                'token' => $result['Credentials']['SessionToken']
            ],
            'table_name' => getenv('SESSION_TABLE')
        ]);

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

        //Now compare with the performance of the SDK
        $dynamodb = new \Aws\DynamoDb\DynamoDbClient(
            $this->credentials
        );
        $dynamoDbClient =  \Aws\DynamoDb\SessionHandler::fromClient(
            $dynamodb,

            $this->credentials +
            [
                'table_name' => getenv('SESSION_TABLE'),
            ]
        );

        $i = 0;
        $start = microtime(true);
        while ($i++ < 20) {
            $data = 'test' . $i;
            $dynamoDbClient->write('TEST', $data);
            $result = $dynamoDbClient->read('TEST');
            $this->assertEquals($data, $result);
        }
        $end = microtime(true);
        $time_sdk = $end - $start;
        echo "Time to read and write 20 sessions in seconds (SDK): " . ($end - $start);


        $dynamoDbClient = $this->dynamoDbClient;
        $i = 0;
        $start = microtime(true);
        while ($i++ < 20) {
            $data = 'test' . $i;
            $dynamoDbClient->write('TEST', $data);
            $result = $dynamoDbClient->read('TEST');
            $this->assertEquals($data, $result);
        }
        $end = microtime(true);
        echo "Time to read and write 20 sessions in seconds: (Dependency-Free)" . ($end - $start);
        $time_dependency_free = $end- $start;


        //Our client should be with 20% of the native client
        $this->assertTrue( $time_dependency_free - $time_sdk  < 0.2*$time_sdk, "Our client is within  20% of the performance of the SDK - $time_dependency_free(Us) versus $time_sdk(SDK)");
    }

    /**
     * Test the official client for comparison
     */
    public function testPerformanceAwsSdk()
    {


    }
}