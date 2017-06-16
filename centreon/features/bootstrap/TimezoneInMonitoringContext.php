<?php

use Centreon\Test\Behat\CentreonContext;
use Centreon\Test\Behat\Monitoring\HostMonitoringDetailsPage;
use Centreon\Test\Behat\Configuration\HostConfigurationPage;
use Centreon\Test\Behat\Monitoring\MonitoringServicesPage;


/**
 * Defines application features from the specific context.
 */
class TimezoneInMonitoringContext extends CentreonContext
{
    private $page;
    private $hostname = 'acceptancetest';
    private $ping = 'Ping';
    

    /**
     *  @Given a host
     */
    public function aHost()
    {
        $this->page = new HostConfigurationPage($this);
        $this->page->setProperties(array(
            'name' => $this->hostname,
            'alias' => $this->hostname,
            'address' => '127.0.0.1',
            'templates' => array('generic-host'),
            'location' => array('Africa/Accra')
        ));
        $this->page->save();
        $this->reloadAllPollers();
        $this->page = new MonitoringServicesPage($this);
        $this->page->scheduleImmediateCheckOnService($this->hostname, $this->ping);
        
    }

    /**
     *  @When I open the host monitoring details page
     */
    public function iOpenTheHostMonitoringDetailsPage()
    {     
        $this->page = new HostMonitoringDetailsPage($this, $this->hostname);     
    }

    /**
     *  @Then the timezone of this host is displayed
     */
    public function ThenTheTimezoneOfThisHostIsDisplayed()
    {
        $properties = $this->page->getProperties();
        
        $this->spin(
            function() {
                $properties = $this->page->getProperties();
                
                if ($properties['timezone'] == 'Africa/Accra') {
 
                    return true;
                }          
            },        
            'Timezone is not displayed: got ' . $properties['timezone'] . 
                ', expected Africa/Accra.',
            10);
    }
}
