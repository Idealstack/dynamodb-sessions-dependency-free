
<a href="https://idealstack.io/">
    <img src="https://idealstack.io/application/themes/idealstack/img/github-banner.svg" alt="Idealstack - the best way to run PHP on AWS" title="Idealstack - the best way to run PHP on AWS" align="right"  />
</a>

# dynamodb-sessions-dependency-free
[![CI](https://github.com/Idealstack/dynamodb-sessions-dependency-free/actions/workflows/testing.yml/badge.svg)](https://github.com/Idealstack/dynamodb-sessions-dependency-free/actions/workflows/testing.yml)

<img src="https://codebuild.us-west-2.amazonaws.com/badges?uuid=eyJlbmNyeXB0ZWREYXRhIjoiVjRSR08rOXNua2IyeWUwZDVPemk3MUNjc09EMUg1aWJlTmR4MCtvNUZOTzNVbXJnbXpxN1VoTEV1QituaGNJSlgybTlhOEJseGJZSGNlZVo5TkFER1prPSIsIml2UGFyYW1ldGVyU3BlYyI6Ik5jd3pmZU1hclIzVmx3V3IiLCJtYXRlcmlhbFNldFNlcmlhbCI6MX0%3D&branch=master" />

An implementation of a session handler for storing sessions in dynamoDB, 
but with no dependencies on the AWS SDK, Guzzle etc.  As a bonus it's also faster!

The [Idealstack](https://idealstack.io) AWS hosting platform uses this to provide transparent support for DynamoDB 
sessions, so users don't need to change anything in their code.  

See our [blog post](https://idealstack.io/blog/faster-dependency-free-php-sessions-dynamodb) about it for instructions on how to use it, setup the required tables etc

# Features

- Essentially  a drop-in replacement for the official session handler in the AWS SDK
- Dependency-free - does not depend on any other composer packages. Only requires the core curl and json extensions be 
enabled in PHP 
- Runs on a;; PHP versions from 5.6 to 8.1
- Does not require an autoloader (although will work fine with one, eg composer)
- Supports most common AWS authentication methods (eg instance profiles, ECS task roles, .aws config files, environment variables)
- **Does not support locking** (that's just because we don't need it, a PR is welcome or raise an issue if you need it)



# Why do you want this?
Possibly you don't.  The [AWS SDK includes a session handler](https://docs.aws.amazon.com/sdk-for-php/v3/developer-guide/service_dynamodb-session-handler.html) that is maintained by AWS and might be a better choice for most people.

Why do you want to store sessions in dynamodb? If you are running PHP in a clustered environment the default file-based
session handler won't work.  

You can store sessions in an SQL database, or Redis, but in many ways dynamodb is a better choice on AWS.
It automatically scales to any number of reads/writes, can be distributed globally, has an elastic pricing model that
is cheaper for small sites etc etc.


Here's a couple of reasons why you might want to use this code over the AWS SDK:

- If you aren't using the AWS SDK for other things, the session handler is going to include a large number of PHP 
classes on every page load that aren't needed.  This potentially slows things down, uses more RAM, IO and Opcache etc
- It's faster.  About 30% faster.  We're talking about the difference between 30ms and 20ms here, so it's unlikely 
that sessions are the bottleneck slowing your app down.  Still, faster is always good.
- If you choose to include this automatically using php's auto_prepend_file - you can get funny side-effects if you use the 
AWS SDK version.  It requires an autoloader, which can mess with your project's own autoloading.  Also it 'pollutes' 
the namespace.  If your code uses a different version of the SDK from the one the sessions use, you should  expect 
problems.  This is the reason why we developed this for [Idealstack](https://idealstack.io) as we make dynamodb sessions
 transparent to our users code.

# Installation
via composer 

`composer require idealstack/dynamodb-sessions-dependency-free`

or clone this repository

# How to use it

Configuration is the same as the AWS SDK version, so read their docs:

https://docs.aws.amazon.com/sdk-for-php/v3/developer-guide/service_dynamodb-session-handler.html



## Creating the table
You configure the DynamoDB table the same as the AWS SDK docs recommend.  However we'd suggest you also use the 
DynamoDB 'ttl' capability to garbage collect your sessions.  Set that on the 'expires' field (this also works with 
the native SDK).  See the [notes in our blog post](https://idealstack.io/blog/faster-dependency-free-php-sessions-dynamodb) about how to setup the table.

## Using it in your code 

````php
use Idealstack\DynamoDbSessionHandlerDependencyFree;
// or if you don't want to use composer auto-loader, try: 
// require(__DIR__ .'/vendor/idealstack/dynamodb-session-handler-dependency-free/src/DynamoDbSessionHandler.php');

(new Idealstack\DynamoDbSessionHandlerDependencyFree\DynamoDbSessionHandler(
[
            'table_name' => 'your-session-table-name',
// Credentials.  In production we recomend you use an instance role so you do not need to hardcode these.
// At least make sure you don't hardcode them and commit them to github!
            'credentials' => [
                'key' => 'AAAAAAAAAAAAAAAAAAAAAA',
                'secret' => 'BBBBBBBBBBBBBBBBBBBBBBBBBBBBBB'
            ],
// These are all defaults. 
//             // Base64 encode data when reading and writing. Avoids problems with binary data,  Note this is 
//             // not the behaviour of the AWS SDK, so set to false if you require compatibility with existing
//             // sessions  created with the SDK
//            'base64' => true, 
//            'hash_key' => 'id',
//
//            // The lifetime of an inactive session before it should be garbage collected. If it isn't provided, 
//            // the actual lifetime value that will be used is ini_get('session.gc_maxlifetime').
//            'session_lifetime' => 1440, // 24 minutes
//            'consistent_reads' => true, //You almost certainly want this to be true
//            'session_locking' => false, //True is not supported
        ]

))->register();
````


# Development
There is a docker environment to test with, using local dynamodb 
`tools/setup` - setup the container and database etc
`tools/test` - run the unit tests
`tools/console` - run a console

To use XDebug

```
tools/console
tools/install-xdebug
composer install
vendor/bin/phpunit
````
