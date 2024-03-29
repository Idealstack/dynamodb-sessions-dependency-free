<?php

use PHPUnit\Framework\TestCase;
use App\Services\Utils;

require_once(__DIR__ . '/../src/AwsClient.php');

/**
 * Class DynamoDBSessionClientTest
 *
 * Test our standalone dependency-free dynamodb client
 */
class DynamoDBSessionAwsClientTest extends TestCase
{
    private $env = [];

    /** @var array Vars we will monkey with in the test and need to save/restore */
    private $envvars = [
        'AWS_CONTAINER_CREDENTIALS_RELATIVE_URI',
        'AWS_ACCESS_KEY_ID',
        'AWS_SECRET_ACCESS_KEY',
        'AWS_CREDENTIALS_FILENAME'
    ];

    /**
     * Save the environment
     * 
     * NOTE this was once a phpunit setup function but it is impossible to maintain compat with older PHP versions and newer phpunit releases
     */
    public function setUpEnv()
    {
        date_default_timezone_set('UTC');
        foreach ($this->envvars as $env) {
            $this->env[$env] = getenv($env);
        }
    }

    /**
     * Restore environment to what it was before the test
     */
    public function restoreEnv()
    {
        foreach ($this->envvars as $env) {
            if ($this->env[$env] === false) {
                putenv($env);
            } else {
                putenv($env . '=' . $this->env[$env]);
            }
        }
    }

    /**
     * Test fetching the AWS credentials
     *
     * @throws ReflectionException
     * @throws \Idealstack\DynamoDbSessionsDependencyFree\AwsClientException
     */
    public function testCredentials()
    {
        $this->setupEnv();
        //Make the credentials method public so we can test it
        $method = new ReflectionMethod('Idealstack\DynamoDbSessionsDependencyFree\AwsClient', 'getCredentials');
        $method->setAccessible(true);

        $AwsClient = new Idealstack\DynamoDbSessionsDependencyFree\AwsClient([
            'region' => getenv('AWS_REGION'),
            'version' => 'latest',
            'credentials' => [
                'key' => getenv('SESSION_AWS_ACCESS_KEY_ID'),
                'secret' => getenv('SESSION_AWS_SECRET_ACCESS_KEY')
            ]
        ]);


        $credentials = $method->invoke($AwsClient);
        $this->assertEquals($credentials['key'], getenv('SESSION_AWS_ACCESS_KEY_ID'));


        //Pass credentials via environment
        putenv('AWS_ACCESS_KEY_ID=TEST2');
        putenv('AWS_SECRET_ACCESS_KEY=TEST2');

        $AwsClient = new Idealstack\DynamoDbSessionsDependencyFree\AwsClient([
            'region' => getenv('AWS_REGION'),
            'version' => 'latest',
        ]);

        $credentials = $method->invoke($AwsClient);
        $this->assertEquals('TEST2', $credentials['key']);


        //Pass credentials via the ECS metadata.  We'll do this by mocking the client
        $old_env = getenv('AWS_CONTAINER_CREDENTIALS_RELATIVE_URI');
        putenv('AWS_CONTAINER_CREDENTIALS_RELATIVE_URI=/test');
        putenv('AWS_ACCESS_KEY_ID');
        putenv('AWS_SECRET_ACCESS_KEY');
        putenv('AWS_CREDENTIALS_FILENAME=/tmp/fake');
        $mock = Mockery::mock(
            Idealstack\DynamoDbSessionsDependencyFree\AwsClient::class,
            [
                [
                    'region' => getenv('AWS_REGION'),
                    'version' => 'latest',
                ]
            ]
        )->makePartial();
        $mock->shouldReceive('curl')->andReturn(
            [
                'content' => json_encode([
                    'AccessKeyId' => 'TEST3',
                    'SecretAccessKey' => 'TEST3',
                    'Token' => 'TEST3',
                    'Expiration' => time(),
                ]),
                'code' => 200
            ]
        );
        $AwsClient = $mock;
        $credentials = $method->invoke($AwsClient);
        $this->assertEquals('TEST3', $credentials['key']);

        //Pass credentials via the EC2 metadata.  We'll do this by mocking the client
        putenv('AWS_CONTAINER_CREDENTIALS_RELATIVE_URI');
        putenv('AWS_ACCESS_KEY_ID');
        putenv('AWS_SECRET_ACCESS_KEY');
        putenv('AWS_CREDENTIALS_FILENAME=/tmp/fake');
        $mock = Mockery::mock(
            Idealstack\DynamoDbSessionsDependencyFree\AwsClient::class,
            [
                [
                    'region' => getenv('AWS_REGION'),
                    'version' => 'latest',
                ]
            ]
        )->makePartial();
        $mock->shouldReceive('curl')->andReturn(
            [
                'content' => json_encode([
                    'AccessKeyId' => 'TEST5',
                    'SecretAccessKey' => 'TEST5',
                    'Token' => 'TEST5',
                    'Expiration' => time(),
                ]),
                'code' => 200
            ]
        );

        $AwsClient = $mock;
        $credentials = $method->invoke($AwsClient);
        $this->assertEquals('TEST5', $credentials['key']);

        //Fallthrough to the ini file
        putenv('AWS_CONTAINER_CREDENTIALS_RELATIVE_URI');
        putenv('AWS_ACCESS_KEY_ID');
        putenv('AWS_SECRET_ACCESS_KEY');


        file_put_contents(
            '/tmp/test-credentials.ini',
            "[default]           
aws_access_key_id = TEST4
aws_secret_access_key = TEST4
"
        );
        putenv('AWS_CREDENTIALS_FILENAME=/tmp/test-credentials.ini');

        $AwsClient = new Idealstack\DynamoDbSessionsDependencyFree\AwsClient([
            'region' => getenv('AWS_REGION'),
            'version' => 'latest',
        ]);
        $credentials = $method->invoke($AwsClient);
        putenv('AWS_CREDENTIALS_FILENAME');
        $this->assertEquals('TEST4', $credentials['key']);
        putenv('AWS_CONTAINER_CREDENTIALS_RELATIVE_URI=' . $old_env);

        $this->restoreEnv();
    }

    /**
     * Test the key returns the example given in the docs https://docs.aws.amazon.com/general/latest/gr/signature-v4-examples.html#signature-v4-examples-other
     * @throws ReflectionException
     */
    public function testSigningKey()
    {
        $this->setupEnv();

        $method = new ReflectionMethod('Idealstack\DynamoDbSessionsDependencyFree\AwsClient', 'getSigningKey');
        $method->setAccessible(true);
        $AwsClient = new Idealstack\DynamoDbSessionsDependencyFree\AwsClient([
            'region' => 'us-east-1',
            'version' => 'latest',
            'credentials' => [
                'key' => 'AKIDEXAMPLE',
                'secret' => 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY'
            ]
        ]);
        $result = $method->invoke($AwsClient, strtotime("2015-08-30"), "iam");
        $this->assertEquals("c4afb1cc5771d871763a393e44b703571b55cc28424d1a5e86da6ed3c154a4b9", bin2hex($result));
        $this->restoreEnv();
    }

    /**
     * Test that the 'canonical request' used in the AWS signature algorithm matches the example in
     * https://docs.aws.amazon.com/general/latest/gr/signature-v4-test-suite.html
     * @throws ReflectionException
     * @throws \Idealstack\DynamoDbSessionsDependencyFree\AwsClientException
     */

    public function testCanonicalRequest()
    {
        $this->setupEnv();


        $AwsClient = new Idealstack\DynamoDbSessionsDependencyFree\AwsClient([
            'region' => 'us-east-1',
            'version' => 'latest',
            'service' => 'service',
            'credentials' => [
                'key' => 'AKIDEXAMPLE',
                'secret' => 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY'
            ]
        ]);


        $method = new ReflectionMethod('Idealstack\DynamoDbSessionsDependencyFree\AwsClient', 'getCanonicalRequest');
        $method->setAccessible(true);

        $result = $method->invoke(
            $AwsClient,
            'GET',
            'https://example.amazonaws.com',
            [
                'Param2' => 'value2',
                'Param1' => 'value1'
            ],
            [
                'X-Amz-Date' => '20150830T123600Z'
            ],
            '',
            strtotime('20150830T123600Z')
        );


        $canonical_request_string = "GET
/
Param1=value1&Param2=value2
host:example.amazonaws.com
x-amz-date:20150830T123600Z

host;x-amz-date
e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855";

        $canonical_request_string_hash = '816cd5b414d056048ba4f7c5386d6e0533120fb1fcfa93762cf0fc39e2cf19e0';

        $this->assertEquals($canonical_request_string, $result['CanonicalRequest']);

        //Hash it
        $this->assertEquals(
            $canonical_request_string_hash,
            hash('sha256', $result['CanonicalRequest'])

        );
        $this->restoreEnv();
    }

    /**
     * Test the AWS request headers match the example in
     * https://docs.aws.amazon.com/general/latest/gr/signature-v4-test-suite.html
     *
     * @throws ReflectionException
     * @throws \Idealstack\DynamoDbSessionsDependencyFree\AwsClientException
     */
    public function testAwsRequestHeaders()
    {
        $this->setupEnv();

        $AwsClient = new Idealstack\DynamoDbSessionsDependencyFree\AwsClient([
            'region' => 'us-east-1',
            'version' => 'latest',
            'service' => 'service',
            'credentials' => [
                'key' => 'AKIDEXAMPLE',
                'secret' => 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY'
            ]
        ]);

        $method = new ReflectionMethod('Idealstack\DynamoDbSessionsDependencyFree\AwsClient', 'getAwsRequestHeaders');
        $method->setAccessible(true);


        $headers = $method->invoke(
            $AwsClient,
            'GET',
            'https://example.amazonaws.com',
            [
                'Param2' => 'value2',
                'Param1' => 'value1'
            ],
            [],
            '',
            strtotime('20150830T123600Z')
        );

        //Check versus the example in https://docs.aws.amazon.com/general/latest/gr/sigv4-calculate-signature.html
        $this->assertEquals('20150830T123600Z', $headers['X-Amz-Date']);

        $expected_auth_header = "AWS4-HMAC-SHA256 Credential=AKIDEXAMPLE/20150830/us-east-1/service/aws4_request, SignedHeaders=host;x-amz-date, Signature=b97d918cfa904a5beff61c982a1b6f458b799221646efd99d3219ec94cdf2500";
        $this->assertEquals($expected_auth_header, $headers['Authorization']);

        $this->restoreEnv();
    }
}
