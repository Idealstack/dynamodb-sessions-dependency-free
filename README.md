# dynamodb-sessions-dependency-free
An implementation of a session handler for storing sessions in dynamoDB, 
but with no dependencies on the AWS SDK, Guzzle etc.  

The [Idealstack](https://idealstack.io) AWS hosting platform uses this to provide transparent support for DynamoDB sessions, so users don't
need to change anything in their code.

# Features

- Drop-in replacement for the session handler in the AWS SDK
- Dependency-free - does not depend on any other composer packages. Requires the curl extension. 
- Does not require an autoloader (although will work fine with one, eg composer)
- Supports most common AWS authentication methods
- Compatible with all PHP versions (even php5.6)
- *Does not support locking* (that's just because we don't need it, a PR is welcome)



# Why do I want this?
Possibly you don't.  The [AWS SDK includes a session handler](https://docs.aws.amazon.com/sdk-for-php/v3/developer-guide/service_dynamodb-session-handler.html) that is maintained by AWS and might be a better choice for most people.

Here's a couple of reasons why you might:

- If you aren't using the AWS SDK, it's going to include a large number of PHP classes on every page load that aren't 
needed.  This potentially slows things down, uses more RAM and Opcache etc
- Note that we haven't done benchmarks yet to prove this is faster.  But I suspect it probably is.
- If you choose to include this automatically using php's auto_prepend_file - you can get funny effects if you use the 
AWS SDK version.  It requires an autoloader, which can mess with your project's own autoloading.  Also it 'pollutes' 
the namespace with it's versions of common libraries like Guzzle.  If your code uses a different version of these 
libraries you should
 expect problems.


# How to use it

Configuration is the same as the AWS SDK version, so read their docs:

https://docs.aws.amazon.com/sdk-for-php/v3/developer-guide/service_dynamodb-session-handler.html

You configure the table the same as they recommend.  However we'd suggest you also use the DynamoDB 'ttl' capability to
garbage collect your sessions.  Set that on the 'expires' field.

````php
use DynamoDbSessionHandlerDependencyFree
(new DynamoDbSessionHandlerDependencyFree/DynamoDbSessionHandler(
[
            'table_name' => 'your-session-table-name',
//Credentials.  In production we recomend you use an instance role so you do not need to hardcode this.
            'credentials' => [
                'key' => 'AAAAAAAAAAAAAAAAAAAAAA',
                'secret' => 'BBBBBBBBBBBBBBBBBBBBBBBBBBBBBB'
            ],
// These are all defaults. 
//            'base64' => true, //Base64 data when reading and writing.  Note this is not the behaviour of the AWS SDK, so set to false if you require compatibility with that.
//            'hash_key' => 'id',
//            //The lifetime of an inactive session before it should be garbage collected. If it isn't provided, the actual lifetime value that will be used is ini_get('session.gc_maxlifetime').
//            'session_lifetime' => 300,
//            'consistent_reads' => true,
//            'session_locking' => false, //True is not supported
//            'max_lock_wait_time' => 15,
//            'min_lock_retry_utime' => 5000,
//            'max_lock_retry_utime' => 50000,
        ]

))->register();
````