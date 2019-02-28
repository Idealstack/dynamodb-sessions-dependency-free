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
            ['table_name' => getenv('SESSION_TABLE')]
        );

    }


    public function testReadWriteWithTemporaryCredentials()
    {
        $stsClient = new \Aws\Sts\StsClient($this->credentials);

        echo getenv('AWS_CONTAINER_CREDENTIALS_RELATIVE_URI');

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

        //Check versus the example in https://docs.aws.amazon.com/general/latest/gr/signature-v4-test-suite.html

        $data = 'test' . rand(0, 10000000);
        $result = $dynamoDbClient->write('TEST', $data);
        $this->assertTrue($result);

        $result = $dynamoDbClient->read('TEST');
        $this->assertEquals($data, $result);
    }


    public function testReadWrite()
    {
        $dynamoDbClient = $this->dynamoDbClient;

        $data = 'test' . rand(0, 10000000);
        $result = $dynamoDbClient->write('TEST', $data);
        $this->assertTrue($result);

        $result = $dynamoDbClient->read('TEST');
        $this->assertEquals($data, $result);
    }


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


}