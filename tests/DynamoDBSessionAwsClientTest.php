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

    public function tearDown()
    {

        //Clear environment
        putenv('AWS_CONTAINER_CREDENTIALS_RELATIVE_URI');
        putenv('AWS_ACCESS_KEY_ID');
        putenv('AWS_SECRET_ACCESS_KEY');
    }

    public function testCredentials()
    {
        //Make the credentials method public so we can test it
        $method = new ReflectionMethod('\DynamoDbSessionsDependencyFree\AwsClient', 'getCredentials');
        $method->setAccessible(true);

        $AwsClient = new \DynamoDbSessionsDependencyFree\AwsClient([
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

        $AwsClient = new \DynamoDbSessionsDependencyFree\AwsClient([
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
        $mock = Mockery::mock(\DynamoDbSessionsDependencyFree\AwsClient::class,
           [ [ 'region' => getenv('AWS_REGION'),
                'version' => 'latest',]]
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


        //Fallthrough to the ini file
        putenv('AWS_CONTAINER_CREDENTIALS_RELATIVE_URI');
        putenv('AWS_ACCESS_KEY_ID');
        putenv('AWS_SECRET_ACCESS_KEY');


        file_put_contents('/tmp/test-credentials.ini',
            "[default]           
aws_access_key_id = TEST4
aws_secret_access_key = TEST4
"
        );
        putenv('AWS_CREDENTIALS_FILENAME=/tmp/test-credentials.ini');

        $AwsClient = new \DynamoDbSessionsDependencyFree\AwsClient([
            'region' => getenv('AWS_REGION'),
            'version' => 'latest',
        ]);
        $credentials = $method->invoke($AwsClient);
        putenv('AWS_CREDENTIALS_FILENAME');
        $this->assertEquals('TEST4', $credentials['key']);
        putenv('AWS_CONTAINER_CREDENTIALS_RELATIVE_URI=' . $old_env);

    }

    /**
     * Test the key returns the example given in the docs https://docs.aws.amazon.com/general/latest/gr/signature-v4-examples.html#signature-v4-examples-other
     * @throws ReflectionException
     */
    public function testSigningKey()
    {

        $method = new ReflectionMethod('\DynamoDbSessionsDependencyFree\AwsClient', 'getSigningKey');
        $method->setAccessible(true);
        $AwsClient = new \DynamoDbSessionsDependencyFree\AwsClient([
            'region' => 'us-east-1',
            'version' => 'latest',
            'credentials' => [
                'key' => 'AKIDEXAMPLE',
                'secret' => 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY'
            ]
        ]);
        $result = $method->invoke($AwsClient, strtotime("2015-08-30"), "iam");
        $this->assertEquals("c4afb1cc5771d871763a393e44b703571b55cc28424d1a5e86da6ed3c154a4b9", bin2hex($result));
    }

    public function testCanonicalRequest()
    {

        //Check versus the example in https://docs.aws.amazon.com/general/latest/gr/signature-v4-test-suite.html

        $AwsClient = new \DynamoDbSessionsDependencyFree\AwsClient([
            'region' => 'us-east-1',
            'version' => 'latest',
            'service' => 'service',
            'credentials' => [
                'key' => 'AKIDEXAMPLE',
                'secret' => 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY'
            ]
        ]);


        $method = new ReflectionMethod('\DynamoDbSessionsDependencyFree\AwsClient', 'getCanonicalRequest');
        $method->setAccessible(true);

        $result = $method->invoke($AwsClient,
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
            strtotime('20150830T123600Z'));


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
        $this->assertEquals($canonical_request_string_hash,
            hash('sha256', $result['CanonicalRequest'])

        );

    }

    public function testAwsRequestHeaders()
    {

        //Check versus the example in https://docs.aws.amazon.com/general/latest/gr/signature-v4-test-suite.html

        $AwsClient = new \DynamoDbSessionsDependencyFree\AwsClient([
            'region' => 'us-east-1',
            'version' => 'latest',
            'service' => 'service',
            'credentials' => [
                'key' => 'AKIDEXAMPLE',
                'secret' => 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY'
            ]
        ]);

        $method = new ReflectionMethod('\DynamoDbSessionsDependencyFree\AwsClient', 'getAwsRequestHeaders');
        $method->setAccessible(true);


        $headers = $method->invoke($AwsClient,
            'GET',
            'https://example.amazonaws.com',
            [
                'Param2' => 'value2',
                'Param1' => 'value1'
            ],
            [
            ],
            '',
            strtotime('20150830T123600Z'));

        //Check versus the example in https://docs.aws.amazon.com/general/latest/gr/sigv4-calculate-signature.html
        $this->assertEquals('20150830T123600Z', $headers['X-Amz-Date']);

        $expected_auth_header = "AWS4-HMAC-SHA256 Credential=AKIDEXAMPLE/20150830/us-east-1/service/aws4_request, SignedHeaders=host;x-amz-date, Signature=b97d918cfa904a5beff61c982a1b6f458b799221646efd99d3219ec94cdf2500";
        $this->assertEquals($expected_auth_header, $headers['Authorization']);
    }

}