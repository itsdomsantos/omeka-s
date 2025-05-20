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
    // Listen for PRE-delete events to capture item set associations
    $sharedEventManager->attach(
        'Omeka\Api\Adapter\ItemAdapter',
        'api.delete.pre',
        [$this, 'handleItemPreDeletion']
    );
    
    // Listen for item deletion events
    $sharedEventManager->attach(
        'Omeka\Api\Adapter\ItemAdapter',
        'api.delete.post',
        [$this, 'handleItemDeletion']
    );
    
    // Track item creation/update
    $sharedEventManager->attach(
        'Omeka\Api\Adapter\ItemAdapter',
        'api.create.post',
        [$this, 'trackItemItemSetRelationship']
    );
    
    $sharedEventManager->attach(
        'Omeka\Api\Adapter\ItemAdapter',
        'api.update.post',
        [$this, 'trackItemItemSetRelationship']
    );
}

/**
 * Capture item data BEFORE deletion
 */
public function handleItemPreDeletion($event)
{
    $request = $event->getParam('request');
    $itemId = $request->getId();
    
    error_log("Item PRE-deletion detected: ID=$itemId", 3, OMEKA_PATH . '/logs/deleting.log');
    
    try {
        // Get the item representation which includes all associations
        $api = $this->getServiceLocator()->get('Omeka\ApiManager');
        $item = $api->read('items', $itemId)->getContent();
        
        // Get item sets and cache them for the post-delete event
        $itemSets = $item->itemSets();
        $itemSetIds = [];
        
        foreach ($itemSets as $itemSet) {
            $itemSetId = $itemSet->id();
            $itemSetIds[] = $itemSetId;
            error_log("Item $itemId belongs to item set $itemSetId ({$itemSet->title()})", 3, OMEKA_PATH . '/logs/deleting.log');
        }
        
        // Get identifier
        $identifier = null;
        $identifierValue = $item->value('dcterms:identifier', ['default' => null]);
        if ($identifierValue) {
            $identifier = (string) $identifierValue;
            error_log("Item identifier: $identifier", 3, OMEKA_PATH . '/logs/deleting.log');
        }
        
        // Store this information in a temporary cache
        $this->cacheItemDeletionInfo($itemId, [
            'itemSetIds' => $itemSetIds,
            'identifier' => $identifier
        ]);
        
    } catch (\Exception $e) {
        error_log("Error capturing pre-deletion data: " . $e->getMessage(), 3, OMEKA_PATH . '/logs/deleting.log');
    }
}

/**
 * Cache item deletion information for use in the post-delete handler
 */
private function cacheItemDeletionInfo($itemId, $info)
{
    // Use temporary file storage for simplicity
    $cacheDir = OMEKA_PATH . '/files/temp';
    if (!is_dir($cacheDir)) {
        mkdir($cacheDir, 0755, true);
    }
    
    $cacheFile = $cacheDir . "/item_deletion_$itemId.json";
    file_put_contents($cacheFile, json_encode($info));
    error_log("Cached pre-deletion info to $cacheFile", 3, OMEKA_PATH . '/logs/deleting.log');
}

/**
 * Get cached item deletion information
 */
private function getCachedItemDeletionInfo($itemId)
{
    $cacheFile = OMEKA_PATH . '/files/temp' . "/item_deletion_$itemId.json";
    
    if (file_exists($cacheFile)) {
        $content = file_get_contents($cacheFile);
        $info = json_decode($content, true);
        error_log("Retrieved cached deletion info for item $itemId", 3, OMEKA_PATH . '/logs/deleting.log');
        
        // Clean up the cache file
        unlink($cacheFile);
        
        return $info;
    }
    
    error_log("No cached deletion info found for item $itemId", 3, OMEKA_PATH . '/logs/deleting.log');
    return null;
}

/**
 * Handle item deletion post-event
 */
public function handleItemDeletion($event)
{
    // Get the deleted item ID from the request
    $request = $event->getParam('request');
    if (!$request) {
        error_log("No request in event parameters", 3, OMEKA_PATH . '/logs/deleting.log');
        return;
    }

    $itemId = $request->getId();
    error_log("Item deletion detected: ID=$itemId", 3, OMEKA_PATH . '/logs/deleting.log');
    
    if (!$itemId) {
        error_log("Failed to retrieve item ID", 3, OMEKA_PATH . '/logs/deleting.log');
        return;
    }
    
    // Get cached pre-deletion information
    $cachedInfo = $this->getCachedItemDeletionInfo($itemId);
    
    $identifier = null;
    $graphId = "0"; // Default graph
    
    if ($cachedInfo) {
        // Use the cached information
        $identifier = $cachedInfo['identifier'] ?? null;
        $itemSetIds = $cachedInfo['itemSetIds'] ?? [];
        
        if (!empty($itemSetIds)) {
            $graphId = $itemSetIds[0]; // Use the first item set as the graph ID
            error_log("Using cached item set ID as graph ID: $graphId", 3, OMEKA_PATH . '/logs/deleting.log');
        }
    } else {
        // Fallback to the previous approach
        try {
            $entityManager = $this->getServiceLocator()->get('Omeka\EntityManager');
            $connection = $entityManager->getConnection();
            
            // Try to get the item sets this item belongs to (likely already deleted)
            $itemSetRows = $connection->fetchAllAssociative(
                "SELECT item_set_id FROM item_item_set WHERE item_id = ?", 
                [$itemId]
            );
            
            if (!empty($itemSetRows)) {
                $graphId = $itemSetRows[0]['item_set_id'];
                error_log("Found item set ID from item_item_set: $graphId", 3, OMEKA_PATH . '/logs/deleting.log');
            }
            
            // Get dcterms:identifier for the deleted item
            $identifierPropertyId = $connection->fetchOne(
                'SELECT id FROM property WHERE local_name = ? AND vocabulary_id = (SELECT id FROM vocabulary WHERE prefix = ?)',
                ['identifier', 'dcterms']
            );
            
            if ($identifierPropertyId) {
                $identifierValue = $connection->fetchOne(
                    'SELECT value FROM value WHERE resource_id = ? AND property_id = ? LIMIT 1',
                    [$itemId, $identifierPropertyId]
                );
                
                if ($identifierValue) {
                    $identifier = $identifierValue;
                    error_log("Found identifier value: $identifier", 3, OMEKA_PATH . '/logs/deleting.log');
                }
            }
        } catch (\Exception $e) {
            error_log("Error in fallback approach: " . $e->getMessage(), 3, OMEKA_PATH . '/logs/deleting.log');
        }
    }
    
    // Log the final values we're using
    error_log("Final values for deleteFromGraphDB: identifier=$identifier, itemId=$itemId, graphId=$graphId", 3, OMEKA_PATH . '/logs/deleting.log');
    
    // Delete from GraphDB using the item set ID directly as the graph ID
    $this->deleteFromGraphDB($identifier, $itemId, $graphId);
}

    
private function deleteFromGraphDB($identifier, $itemId, $graphId)
{
    // GraphDB configuration - update these endpoints to match your setup
    $graphdbEndpoint = "http://localhost:7200/repositories/megalod/statements";
    $baseDataGraphUri = "https://purl.org/megalod/";
    $graphUri = $baseDataGraphUri . $graphId . "/";
    
    error_log("Attempting to delete from GraphDB: identifier=$identifier, itemId=$itemId, graphUri=$graphUri", 3, OMEKA_PATH . '/logs/deleting.log');
    
    try {
        // First try the specific graph
        $deleted = false;
        
        // Different delete strategies based on available information
        if ($identifier) {
            // If we have a specific identifier, target resources with that identifier
            error_log("Using identifier-based deletion strategy", 3, OMEKA_PATH . '/logs/deleting.log');
            $result = $this->deleteResourceByIdentifier($graphdbEndpoint, $graphUri, $identifier);
            $deleted = $result->isSuccess();
        } else {
            // If no identifier, try to delete based on Omeka item ID patterns
            error_log("Using ID-based deletion strategy as fallback", 3, OMEKA_PATH . '/logs/deleting.log');
            $result = $this->deleteResourceByOmekaId($graphdbEndpoint, $graphUri, $itemId);
            $deleted = $result->isSuccess();
        }
        
        // If we failed to delete from the specific graph and we're not already trying the default,
        // try the default graph as a fallback
        if (!$deleted && $graphId !== "0") {
            $defaultGraphUri = $baseDataGraphUri . "0/";
            error_log("First attempt failed, trying default graph: $defaultGraphUri", 3, OMEKA_PATH . '/logs/deleting.log');
            
            if ($identifier) {
                $this->deleteResourceByIdentifier($graphdbEndpoint, $defaultGraphUri, $identifier);
            } else {
                $this->deleteResourceByOmekaId($graphdbEndpoint, $defaultGraphUri, $itemId);
            }
        }
        
        error_log("Deletion from GraphDB completed for item $itemId", 3, OMEKA_PATH . '/logs/deleting.log');
    } catch (\Exception $e) {
        error_log("Error deleting from GraphDB: " . $e->getMessage(), 3, OMEKA_PATH . '/logs/deleting.log');
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

/**
 * Track item-to-itemset relationships when items are created or updated
 */
public function trackItemItemSetRelationship($event)
{
    $item = $event->getParam('response')->getContent();
    
    if (!$item instanceof \Omeka\Api\Representation\ItemRepresentation) {
        return;
    }
    
    $itemId = $item->id();
    $itemSets = $item->itemSets();
    
    if (empty($itemSets)) {
        error_log("Item $itemId has no item sets", 3, OMEKA_PATH . '/logs/tracking.log');
        return;
    }
    
    // Store mapping in module settings
    $moduleSettings = $this->getServiceLocator()->get('Omeka\Settings');
    $itemItemSetMap = $moduleSettings->get('addtriplestore_item_itemset_map', []);
    
    foreach ($itemSets as $itemSet) {
        $itemSetId = $itemSet->id();
        $itemItemSetMap[$itemId] = $itemSetId;
        error_log("Tracking item $itemId in item set $itemSetId ({$itemSet->title()})", 3, OMEKA_PATH . '/logs/tracking.log');
        // Only store the first one for simplicity
        break;
    }
    
    $moduleSettings->set('addtriplestore_item_itemset_map', $itemItemSetMap);
    error_log("Updated item-itemset mapping in module settings", 3, OMEKA_PATH . '/logs/tracking.log');
}


}