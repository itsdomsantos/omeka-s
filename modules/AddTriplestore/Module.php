<?php

namespace AddTriplestore;

use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\Mvc\Controller\AbstractController;
use Laminas\Mvc\MvcEvent;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Laminas\Http\Client;
use Omeka\Module\AbstractModule;

class Module extends AbstractModule
{
    /**
     * Get module configuration
     *
     * @return array Configuration array
     */
    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }

    /**
     * Register event listeners during bootstrap
     *
     * @param MvcEvent $event
     */
    public function onBootstrap(MvcEvent $event)
    {
        parent::onBootstrap($event);
        $this->attachListeners($event->getApplication()->getServiceManager()->get('SharedEventManager'));
    }

    /**
     * Attach listeners to the shared event manager
     *
     * @param SharedEventManagerInterface $sharedEventManager
     */
    public function attachListeners(SharedEventManagerInterface $sharedEventManager)
    {
        // Listen for item deletion events
        $sharedEventManager->attach(
            'Omeka\Api\Adapter\ItemAdapter',
            'api.delete.post',
            [$this, 'handleItemDeletion']
        );
    }

    public function handleItemDeletion($event)
    {
        // When an item is deleted, we get an entity representation, not a resource representation
        $item = $event->getParam('response')->getContent();
        
        // Make sure we have a valid item entity
        if (!$item || !($item instanceof \Omeka\Entity\Item)) {
            error_log("Invalid item entity in deletion event", 3, OMEKA_PATH . '/logs/deleteing.log');
            return;
        }
        
        $itemId = $item->getId();
        error_log("Item deletion detected: ID=$itemId", 3, OMEKA_PATH . '/logs/deleteing.log');
        
        $identifier = null;
        $excavationId = "0"; // Default graph
        
        try {
            $entityManager = $this->getServiceLocator()->get('Omeka\EntityManager');
            
            // Get identifier value using direct entity access
            // First get the dcterms:identifier property ID
            $connection = $entityManager->getConnection();
            $propertyId = $connection->fetchOne(
                'SELECT id FROM property WHERE local_name = ? AND vocabulary_id = (SELECT id FROM vocabulary WHERE prefix = ?)',
                ['identifier', 'dcterms']
            );
            
            if ($propertyId) {
                // Get the value using the property ID
                $value = $connection->fetchOne(
                    'SELECT value FROM value WHERE resource_id = ? AND property_id = ? LIMIT 1',
                    [$itemId, $propertyId]
                );
                
                if ($value) {
                    $identifier = $value;
                    error_log("Found identifier value: $identifier", 3, OMEKA_PATH . '/logs/deleteing.log');
                }
            }
            
            // Get item sets directly from database
            $itemSetIds = $connection->fetchAllAssociative(
                'SELECT item_set_id FROM item_item_set WHERE item_id = ?',
                [$itemId]
            );
            
            if (!empty($itemSetIds)) {
                // Log all item set IDs for this item
                foreach ($itemSetIds as $row) {
                    $itemSetId = $row['item_set_id'];
                    error_log("Item $itemId belongs to item set: $itemSetId", 3, OMEKA_PATH . '/logs/deleteing.log');
                }
                
                // Get all site settings to find excavation mappings
                $siteSettings = $this->getServiceLocator()->get('Omeka\Settings\Site');
                $sites = $entityManager->getRepository('Omeka\Entity\Site')->findAll();
                
                foreach ($sites as $site) {
                    $siteId = $site->getId();
                    $mappings = $siteSettings->get('excavation_itemset_mappings', [], $siteId);
                    
                    foreach ($itemSetIds as $row) {
                        $itemSetId = $row['item_set_id'];
                        
                        if (isset($mappings[$itemSetId])) {
                            $excavationId = $mappings[$itemSetId];
                            error_log("Found excavation ID: $excavationId for item set: $itemSetId", 3, OMEKA_PATH . '/logs/deleteing.log');
                            break 2; // Break both loops
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            error_log("Error retrieving item metadata: " . $e->getMessage(), 3, OMEKA_PATH . '/logs/deleteing.log');
        }
        
        // Delete from GraphDB
        $this->deleteFromGraphDB($identifier, $itemId, $excavationId);
    }
    
    /**
     * Delete item data from GraphDB
     *
     * @param string $identifier Item identifier
     * @param int $itemId Item ID (fallback if identifier is not available)
     * @param string $excavationId Excavation identifier for graph selection
     */
    private function deleteFromGraphDB($identifier, $itemId, $excavationId)
    {
        // GraphDB configuration - update these endpoints to match your setup
        $graphdbEndpoint = "http://localhost:7200/repositories/megalod/statements";
        $baseDataGraphUri = "https://purl.org/megalod/";
        $graphUri = $baseDataGraphUri . $excavationId . "/";
        
        error_log("Attempting to delete from GraphDB: identifier=$identifier, itemId=$itemId, excavationId=$excavationId", 3, OMEKA_PATH . '/logs/deleteing.log');
        
        try {
            // Different delete strategies based on available information
            if ($identifier) {
                // If we have a specific identifier, target resources with that identifier
                error_log("Using identifier-based deletion strategy", 3, OMEKA_PATH . '/logs/deleteing.log');
                $this->deleteResourceByIdentifier($graphdbEndpoint, $graphUri, $identifier);
            } else {
                // If no identifier, try to delete based on Omeka item ID patterns
                error_log("Using ID-based deletion strategy as fallback", 3, OMEKA_PATH . '/logs/deleteing.log');
                $this->deleteResourceByOmekaId($graphdbEndpoint, $graphUri, $itemId);
            }
            
            error_log("Deletion from GraphDB completed for item $itemId in graph $graphUri", 3, OMEKA_PATH . '/logs/deleteing.log');
        } catch (\Exception $e) {
            error_log("Error deleting from GraphDB: " . $e->getMessage(), 3, OMEKA_PATH . '/logs/deleteing.log');
        }
    }
    
    /**
     * Delete a resource from GraphDB using its identifier
     *
     * @param string $endpoint GraphDB endpoint
     * @param string $graphUri Graph URI
     * @param string $identifier Resource identifier
     */
    private function deleteResourceByIdentifier($endpoint, $graphUri, $identifier)
    {
        // First, log what we're trying to do
        error_log("Attempting to delete resource with identifier '$identifier' from graph $graphUri", 3, OMEKA_PATH . '/logs/deleteing.log');
        
        // Build a SPARQL query to delete the resource and related triples
        $query = "
            PREFIX rdf: <http://www.w3.org/1999/02/22-rdf-syntax-ns#>
            PREFIX dcterms: <http://purl.org/dc/terms/>
            
            WITH <$graphUri>
            DELETE {
                ?s ?p ?o .
                ?related ?rel ?s .
            }
            WHERE {
                # Find the main resource with this identifier
                ?s dcterms:identifier \"$identifier\" .
                ?s ?p ?o .
                
                # Optional pattern to find resources that reference this one
                OPTIONAL {
                    ?related ?rel ?s .
                }
            }
        ";
        
        error_log("SPARQL Query: $query", 3, OMEKA_PATH . '/logs/deleteing.log');
        return $this->executeSparqlUpdate($endpoint, $query);
    }
    
    /**
     * Delete a resource from GraphDB using patterns based on Omeka ID
     *
     * @param string $endpoint GraphDB endpoint
     * @param string $graphUri Graph URI
     * @param int $itemId Omeka item ID
     */
    private function deleteResourceByOmekaId($endpoint, $graphUri, $itemId)
    {
        // First, log what we're trying to do
        error_log("Attempting to delete resource related to Omeka item ID $itemId from graph $graphUri", 3, OMEKA_PATH . '/logs/deleteing.log');
        
        // Build a SPARQL query to delete resources that might be related to this Omeka item
        // This is a fallback when we don't have the original identifier
        $query = "
            PREFIX rdf: <http://www.w3.org/1999/02/22-rdf-syntax-ns#>
            
            WITH <$graphUri>
            DELETE {
                ?s ?p ?o .
                ?related ?rel ?s .
            }
            WHERE {
                # Try to match resources that might be related to this Omeka item
                ?s ?anyProp ?anyValue .
                FILTER(CONTAINS(STR(?s), '$itemId'))
                
                # Get all properties and values
                ?s ?p ?o .
                
                # Optional pattern to find resources that reference this one
                OPTIONAL {
                    ?related ?rel ?s .
                }
            }
        ";
        
        error_log("SPARQL Query: $query", 3, OMEKA_PATH . '/logs/deleteing.log');
        return $this->executeSparqlUpdate($endpoint, $query);
    }
    
    /**
     * Execute a SPARQL UPDATE query against GraphDB
     *
     * @param string $endpoint GraphDB endpoint
     * @param string $query SPARQL query
     * @return \Laminas\Http\Response
     * @throws \Exception
     */
    private function executeSparqlUpdate($endpoint, $query)
    {
        $client = new Client();
        $client->setMethod('POST');
        $client->setUri($endpoint);
        $client->setHeaders([
            'Content-Type' => 'application/sparql-update',
            'Accept' => 'application/json'
        ]);
        $client->setRawBody($query);
        
        try {
            $response = $client->send();
            
            // Log both success and failure
            $statusCode = $response->getStatusCode();
            if ($response->isSuccess()) {
                error_log("GraphDB query executed successfully: Status $statusCode", 3, OMEKA_PATH . '/logs/deleteing.log');
            } else {
                $errorMsg = "GraphDB query failed: " . $statusCode . " - " . $response->getBody();
                error_log($errorMsg, 3, OMEKA_PATH . '/logs/deleteing.log');
                throw new \Exception($errorMsg);
            }
            
            return $response;
        } catch (\Exception $e) {
            error_log("Exception when executing SPARQL query: " . $e->getMessage(), 3, OMEKA_PATH . '/logs/deleteing.log');
            throw $e;
        }
    }
    
    /**
     * Get service locator
     * 
     * @return ServiceLocatorInterface
     */
    public function getServiceLocator()
    {
        return $this->serviceLocator;
    }
}