<?php

declare(strict_types=1);

/**
 * @file
 * Feature context Behat testing.
 */

use Behat\Behat\Context\Context;
use Behat\MinkExtension\Context\MinkContext;

/**
 * Defines application features from the specific context.
 */
class FeatureContext extends MinkContext implements Context
{

  /**
     * Go to the phpserver test page.
     *
     *
     * @Given /^(?:|I )am on (?:|the )phpserver test page$/
     * @When /^(?:|I )go to (?:|the )phpserver test page$/
     */
    public function goToPhpServerTestPage(): void
    {
        $this->getSession()->visit('http://localhost:8888/testpage.html');
    }
}
