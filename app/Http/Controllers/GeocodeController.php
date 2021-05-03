<?php

namespace App\Http\Controllers;

// For Guzzle Adaptor and Message factory..
use Http\Message\MessageFactory\GuzzleMessageFactory;
use Http\Adapter\Guzzle7\Client;

// Necessary maps SDK directives.
use Ivory\GoogleMap\Base\Coordinate;
use Ivory\GoogleMap\Service\DistanceMatrix\DistanceMatrixService;
use Ivory\GoogleMap\Service\Base\Location\AddressLocation;
use Ivory\GoogleMap\Service\Base\Location\CoordinateLocation;
use Ivory\GoogleMap\Service\DistanceMatrix\Request\DistanceMatrixRequest;

use App\Components\Forms\GeocodeForm;
use App\Components\Validation\FormValidationException;
use Exception;
use Illuminate\Session\Store;
use Slim\Slim;

/**
 * Home page controller
 *
 * @author Benjamin Ulmer
 * @link http://github.com/remluben/slim-boilerplate
 */
class GeocodeController
{
    /**
     * @var \Slim\Slim
     */
    private $app;

    /**
     * @var \App\Components\Forms\GeocodeForm
     */
    private $geocode;

    /**
     * @var \Illuminate\Session\Store
     */
    private $session;

    /**
     * @var \AlexaCRM\WebAPI\Client
     */
    private $client;

    public function __construct(Slim $app, Store $session, GeocodeForm $geocode)
    {
        $this->app = $app;
        $this->GeocodeForm = $geocode;
        $this->session = $session;
        $this->client = $this->init_client();
    }

    /**
     * Initialize Dynamics Client
     */
    private function init_client()
    {

        // Legacy - Doesn't work well in all instances.
        // return $client = \AlexaCRM\WebAPI\ClientFactory::createOnlineClient(
        //     'https://contoso.crm.dynamics.com',
        //     '00000000-0000-0000-0000-000000000000',
        //     'Application Secret'
        // );

        // Initialize IOrganization settings instance.
        $settings = new \AlexaCRM\WebAPI\OData\OnlineSettings();
        $settings->instanceURI = getenv('dynamics_website_url');
        $settings->applicationID = getenv('dynamics_application');
        $settings->applicationSecret = getenv('dynamics_azure_token');
        $settings->tlsVerifyPeers = false;
        $settings->apiVersion = '9.1';

        // Required for AAD Oauth2.0 middleware verification.
        $middleware = new \AlexaCRM\WebAPI\OData\OnlineAuthMiddleware($settings);

        // Return initialized instance.
        return new \AlexaCRM\WebAPI\Client(new \AlexaCRM\WebAPI\OData\Client($settings, $middleware));
    }

    /**
     * Fetch related records pertaining to the polled Lead using its leadid attribute.
     */
    private function runFetchXMLQueryEntities($entity, $column, $value)
    {
        try {

            $query = new \AlexaCRM\Xrm\Query\QueryByAttribute($entity);
            $query->AddAttributeValue($column, $value);
            $query->ColumnSet = new \AlexaCRM\Xrm\ColumnSet( array('new_latitude', 'new_longitude', 'cr4f2_fullname') );
            $query = $this->client->RetrieveMultiple($query);
            if ( count( $query->Entities ) ) {
                return $this->processData( $query->Entities );
            } else {
                return array();
            }
        } catch (Exception $e) {

            if ( 1 == getenv('app.debug') ) {
                dd($e->getMessage());
            } else {
                return array();
            }
        }
    }

    /**
     * Weans out the unnecessary data from usable ones.
     */
    private function processData($data)
    {
        return array_map( function( $tuple ) {
            $addressLatLong = null;
            $location_tuple = array(
                'id'        => empty( $tuple->Attributes['cr4f2_agentsandrealtorid'] ) ? null : $tuple->Attributes['cr4f2_agentsandrealtorid'],
                'name'      => empty( $tuple->Attributes['cr4f2_fullname'] ) ? null : $tuple->Attributes['cr4f2_fullname'],
                'latitude'  => empty( $tuple->Attributes['new_latitude'] ) ? null : $tuple->Attributes['new_latitude'],
                'longitude' => empty( $tuple->Attributes['new_longitude'] ) ? null : $tuple->Attributes['new_longitude'],
            );

            return $location_tuple;
        }, $data);
    }

    /**
     * Fetch then validate Lead.
     */
    private function fetchLeadLocation($leadID)
    {
        $location = null;
        $geoLocation = (object) array();

        try {
            $retrievedLead = $this->client->Retrieve('lead', $leadID, new \AlexaCRM\Xrm\ColumnSet(array('new_latitude', 'new_longitude', 'new_street')));

            if (!isset($retrievedLead)) {
                throw new Exception("No lead with GUID {$leadID} exists.");
            } elseif (!isset($retrievedLead->Attributes) && !count($retrievedLead->Attributes)) {
                throw new Exception("Lead with GUID {$leadID} has incomplete location information.");
            } else {

                // Street gets first dibs, but we dip out if we get something more precise.
                if (!empty($retrievedLead->Attributes['new_street'])) {
                    $location = $retrievedLead->Attributes['new_street'];
                    $geoLocation->street = $location;
                    $geoLocation->preferred = 'street';
                }

                // Prioritized below the street address. One must use LatLong if it is valid even if there's a street address.
                if (empty($retrievedLead->Attributes['new_latitude']) && empty($retrievedLead->Attributes['new_longitude'])) {
                    if (!$location) {
                        throw "Location information validation failed. No street address found as fallback.";
                    }
                } else {
                    // Validating the LatLong coords.
                    if (abs((float)$retrievedLead->Attributes['new_latitude']) <= 90 && abs((float)$retrievedLead->Attributes['new_longitude']) <= 180) {
                        $location = str_replace('', ' ', $retrievedLead->Attributes['new_latitude'] . ',' . $retrievedLead->Attributes['new_longitude']);
                        $geoLocation->latLong = $location;
                        $geoLocation->latitude = $retrievedLead->Attributes['new_latitude'];
                        $geoLocation->longitude = $retrievedLead->Attributes['new_longitude'];
                        $geoLocation->preferred = 'latLong';
                    } else {

                        // Bail.
                        if ( ! $location ) {
                            throw "Location information validation failed.";
                        }
                    }
                }
            }
        } catch (Exception $e) {
            if ( 1 == getenv('app.debug') ) {
                dd($e->getMessage());
            }
            return false;
        }

        return $geoLocation;
    }

    /**
     * Returns coordinate for GMap Matrix. Overloaded to account for both string and posX,posY scenarios.
     */
    private function prepareCoordinatesObject( $latLong, $elementY = false ) {
        if ( false === $elementY ) {
            $latlong = explode(',', $latLong);
            return new CoordinateLocation( new Coordinate( $latLong[0], $latLong[1]) );
        } else {
            return new CoordinateLocation( new Coordinate( $latLong, $elementY ) );
        }
    }
    

    /**
     * Returns coordinate for GMap Matrix. Overloaded to account for both string and posX,posY scenarios.
     */
    private function prepareDestinations() {
        $count = 0;
        $arrayDestinations = array();

        foreach( $this->agentsAndRealtors as $locationTuple ) {
                $arrayDestinations[] = $this->prepareCoordinatesObject( $locationTuple['latitude'], $locationTuple['longitude'] );
            ++$count;
        }

        return $arrayDestinations;
    }

    /**
     * Delegate computation of Google Maps Distance Matrix to the Maps API service.
     */
    private function invokeDistanceMatrix() {
        $distanceMatrix = new DistanceMatrixService( new Client(), new GuzzleMessageFactory() );
        $distanceMatrix->setKey( env('google_maps_API_key') );


        $response = $distanceMatrix->process( new DistanceMatrixRequest( 
            array( $this->prepareCoordinatesObject( $this->leadLocation->latitude, $this->leadLocation->longitude ) ),
            $this->prepareDestinations(),
        ));

        $count = 0;
        if ( 'OK' === $response->getStatus() )
        foreach ( $response->getRows() as $row) {
            foreach ($row->getElements() as $element) {
                if ( 'OK' === $element->getStatus() ) {
                    $this->agentsAndRealtors[$count]['location'] = $element;
                    ++$count;
                    break;
                }
            }    
        }
    }

    /**
     * Handle Index landing.
     */
    public function indexAction()
    {
        $this->app->render('geocoding.twig',
        array(
            'message'  => $this->session->get('message'),
        ));
    }

    /**
     * Handle POST action.
     */
    public function postAction()
    {
        try {
            $this->GeocodeForm->validate($this->app->request()->params());
        } catch (FormValidationException $e) {
            $this->session->flash('message', 'Invalid Lead UUID provided. Please try again with a valid UUID');
            $this->app->response->redirect($this->app->urlFor('geocodingIndex'));
            return;
        }

        $leadID = $this->app->request()->params()['leadid']; // '805a0dff-b76f-eb11-b0b0-000d3a5319cc'
        $this->agentsAndRealtors = $this->runFetchXMLQueryEntities('cr4f2_agentsandrealtor', 'cr4f2_leadtoagentrealtor', $leadID);
        $this->leadLocation = $this->fetchLeadLocation($leadID);
        $this->invokeDistanceMatrix();
        dd( array(
            '$agent'   => $this->agentsAndRealtors,
            '$lead'    => $this->leadLocation,
            'message'  => $this->session->get('message'),
            'errors'   => $this->session->get('errors'),
            'oldInput' => $this->session->get('input')
        ));
        $this->app->render('geocoding_results.twig', array(
            '$agent'   => $this->agentsAndRealtors,
            '$lead'    => $this->leadLocation,
            'message'  => $this->session->get('message'),
            'errors'   => $this->session->get('errors'),
            'oldInput' => $this->session->get('input')
        ));
    }
}
