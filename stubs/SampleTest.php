<?php

/**
 * Class SampleTest.
 *
 * @mixin PHPUnit_Framework_TestCase
 */
class SampleTest extends SapphireTest
{

    /**
     * Test that the HomePage exists.
     *
     * @return void
     */
    public function testBasicExample()
    {
        $response = Director::test('/home');
        $this->assertContains('SilverStripe', $response->getBody());
    }

}
