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
    private $processedDeletions = [];

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
 * THIS method is called when the module is bootstrapped.
 * It is used to set up the ACL for the guest user role and permissions.
 * @param \Laminas\Mvc\MvcEvent $event
 * @return void
 */
public function onBootstrap(MvcEvent $event)
{
    parent::onBootstrap($event);
    
    $acl = $this->getServiceLocator()->get('Omeka\Acl');



    if (!$acl->hasRole('guest')) {
        $acl->addRole('guest', 'researcher');
        $acl->addRoleLabel('guest', 'Site Visitor');
    }
    

    $acl->allow(
    null,
    ['AddTriplestore\Controller\Site\Index'],
    ['index', 'search', 'viewDetails', 'processCollectingForm', 'downloadTtl', 'aboutUs', 'upload', 'login', 'signup', 'logout', 'dashboard', 'myData', 'processFileUpload', 'uploadTtlData', 'downloadTemplate', 'sparql']
);
    

    $acl->allow('guest', [
        'Omeka\Entity\Item',
        'Omeka\Entity\ItemSet', 
        'Omeka\Entity\Media',
        'Omeka\Api\Adapter\ItemAdapter',
        'Omeka\Api\Adapter\ItemSetAdapter',
        'Omeka\Api\Adapter\MediaAdapter'
    ], ['create', 'update', 'delete', 'read']); 
    

    $acl->allow('guest', [
        'Omeka\Controller\Api',
    ], ['create', 'update', 'delete', 'read']); 

    $acl->allow('guest', [
        'Omeka\Entity\ResourceClass',
        'Omeka\Entity\ResourceTemplate',
        'Omeka\Api\Adapter\ResourceClassAdapter',
        'Omeka\Api\Adapter\ResourceTemplateAdapter'
    ], ['read']);
}


     

/**
 * This method allows guest users to create item sets.
 * It checks if the user is authenticated and has the 'guest' role.
 * If so, it sets the owner of the item set to the guest user.
 * @param \Laminas\Mvc\MvcEvent $event
 * @return void
 */
public function allowGuestUserCreateItems($event)
{
    $services = $this->getServiceLocator();
    $auth = $services->get('Omeka\AuthenticationService');
    
    if ($auth->hasIdentity()) {
        $user = $auth->getIdentity();
        if ($user->getRole() === 'guest') {

            $request = $event->getParam('request');
            $data = $request->getContent();
            $data['o:owner'] = ['o:id' => $user->getId()];
            $request->setContent($data);
        }
    }
}

/**
 * This method allows guest users to create item sets.
 * It checks if the user is authenticated and has the 'guest' role.
 * If so, it sets the owner of the item set to the guest user.
 * @param \Laminas\Mvc\MvcEvent $event
 * @return void
 */
public function allowGuestUserCreateItemSets($event)
{
    $services = $this->getServiceLocator();
    $auth = $services->get('Omeka\AuthenticationService');
    
    if ($auth->hasIdentity()) {
        $user = $auth->getIdentity();
        if ($user->getRole() === 'guest') {

            $request = $event->getParam('request');
            $data = $request->getContent();
            $data['o:owner'] = ['o:id' => $user->getId()];
            $request->setContent($data);
        }
    }
}


/**
 * This method redirects guest users away from admin routes.
 * It checks if the current route is an admin route or a user profile route.
 * @param \Laminas\Mvc\MvcEvent $event
 * @return \Laminas\Stdlib\ResponseInterface
 */
public function redirectGuestsFromAdmin(MvcEvent $event)
{
    $match = $event->getRouteMatch();
    if (!$match) {
        return;
    }

    $routeName = $match->getMatchedRouteName();
    

    $isAdminRoute = strpos($routeName, 'admin') === 0;
    $isUserProfileRoute = $routeName === 'admin/id';
    

    if ($isAdminRoute || $isUserProfileRoute) {
        $auth = $event->getApplication()->getServiceManager()->get('Omeka\AuthenticationService');
        $user = $auth->getIdentity();
        

        if ($user && $user->getRole() === 'guest') {

            $api = $event->getApplication()->getServiceManager()->get('Omeka\ApiManager');
            $sites = $api->search('sites', ['limit' => 1])->getContent();
            $site = isset($sites[0]) ? $sites[0] : null;
            

            error_log('Redirecting guest user away from admin route: ' . $routeName, 3, OMEKA_PATH . '/logs/guest-redirect.log');
            

            $session = new \Laminas\Session\Container('site_user');
            $siteSlug = $session->allowedSite ?: ($site ? $site->slug() : 'default');
            

            $url = $event->getRouter()->assemble(
                ['site-slug' => $siteSlug],
                ['name' => 'site/add-triplestore/dashboard']
            );
            
            $response = $event->getResponse();
            $response->getHeaders()->addHeaderLine('Location', $url);
            $response->setStatusCode(302);
            return $response;
        }
    }
}
    /**
     * This method attaches event listeners to the shared event manager.
     * It listens for item deletion events and tracks item-item set relationships.
     * @param \Laminas\EventManager\SharedEventManagerInterface $sharedEventManager
     * @return void
     */
    public function attachListeners(SharedEventManagerInterface $sharedEventManager)
    {

        $sharedEventManager->attach(
            'Omeka\Api\Adapter\ItemAdapter',
            'api.delete.pre',
            [$this, 'handleItemPreDeletion']
        );
        
        $sharedEventManager->attach(
            'Omeka\Api\Adapter\ItemAdapter',
            'api.delete.post',
            [$this, 'handleItemDeletion']
        );
        
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
    $sharedEventManager->attach(
        'Zend\Mvc\Application',  
        'route',
        [$this, 'redirectGuestsFromAdmin']
    );
    

    $sharedEventManager->attach(
        'Laminas\Mvc\Application',
        'route',
        [$this, 'redirectGuestsFromAdmin']
    );
    }



    /**
     * This method captures item data BEFORE deletion.
     * It logs the item ID, item sets, and identifier.
     * @param mixed $event
     * @return void
     */
    public function handleItemPreDeletion($event)
    {
        $request = $event->getParam('request');
        $itemId = $request->getId();
        
        error_log("Item PRE-deletion detected: ID=$itemId", 3, OMEKA_PATH . '/logs/finalDelete.log');
        
        try {

            $api = $this->getServiceLocator()->get('Omeka\ApiManager');
            

            $item = $api->read('items', $itemId)->getContent();
            

            $settings = $this->getServiceLocator()->get('Omeka\Settings');
            $itemDeletionInfo = $settings->get('addtriplestore_item_deletion_info', []);
            

            $itemSets = $item->itemSets();
            $itemSetIds = [];
            
            foreach ($itemSets as $itemSet) {
                $itemSetId = $itemSet->id();
                $itemSetIds[] = $itemSetId;
                error_log("Item $itemId belongs to item set $itemSetId ({$itemSet->title()})", 3, OMEKA_PATH . '/logs/finalDelete.log');
            }
            

            $identifier = null;
            $identifierValue = $item->value('dcterms:identifier', ['default' => null]);
            if ($identifierValue) {
                $identifier = (string) $identifierValue;
                error_log("Item identifier: $identifier", 3, OMEKA_PATH . '/logs/finalDelete.log');
            }
            

            $itemDeletionInfo[$itemId] = [
                'itemSetIds' => $itemSetIds,
                'identifier' => $identifier,
                'timestamp' => time()
            ];
            

            $settings->set('addtriplestore_item_deletion_info', $itemDeletionInfo);
            error_log("Stored pre-deletion info in module settings for item $itemId", 3, OMEKA_PATH . '/logs/finalDelete.log');
            
        } catch (\Exception $e) {
            error_log("Error capturing pre-deletion data: " . $e->getMessage(), 3, OMEKA_PATH . '/logs/finalDelete.log');
        }
    }

    /**
     * This method caches item deletion information for use in the post-delete handler.
     * It stores the item ID, item sets, and identifier in a temporary file.
     * @param mixed $itemId
     * @param mixed $info
     * @return void
     */
    private function cacheItemDeletionInfo($itemId, $info)
    {

        $cacheDir = OMEKA_PATH . '/files/temp';
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }
        
        $cacheFile = $cacheDir . "/item_deletion_$itemId.json";
        file_put_contents($cacheFile, json_encode($info));
        error_log("Cached pre-deletion info to $cacheFile", 3, OMEKA_PATH . '/logs/finalDelete.log');
    }

    /**
     * This method retrieves cached item deletion information for use in the post-delete handler.
     * @param mixed $itemId
     * @return array|null
     */
    private function getCachedItemDeletionInfo($itemId)
    {

        $cacheDir = OMEKA_PATH . '/files/temp';
        $cacheFile = $cacheDir . "/item_deletion_$itemId.json";
        
        error_log("Looking for cached file: $cacheFile", 3, OMEKA_PATH . '/logs/finalDelete.log');
        
        if (file_exists($cacheFile)) {
            $content = file_get_contents($cacheFile);
            $info = json_decode($content, true);
            error_log("Retrieved cached deletion info for item $itemId: " . print_r($info, true), 3, OMEKA_PATH . '/logs/finalDelete.log');
            

            unlink($cacheFile);
            
            return $info;
        }
        
        error_log("No cached deletion info found for item $itemId", 3, OMEKA_PATH . '/logs/finalDelete.log');
        return null;
    }



    /**
     * This method handles item deletion events.
     * It retrieves the item ID from the request,
     * @param mixed $event
     * @return void
     */
    public function handleItemDeletion($event)
    {
        $request = $event->getParam('request');
        if (!$request) {
            error_log("No request in event parameters", 3, OMEKA_PATH . '/logs/finalDelete.log');
            return;
        }

        $itemId = $request->getId();
        error_log("=== ENHANCED ITEM DELETION PROCESSING ===", 3, OMEKA_PATH . '/logs/finalDelete.log');
        error_log("Processing deletion for item ID: $itemId", 3, OMEKA_PATH . '/logs/finalDelete.log');
        
        if (!$itemId) {
            error_log("Failed to retrieve item ID", 3, OMEKA_PATH . '/logs/finalDelete.log');
            return;
        }
        

        $session = new \Laminas\Session\Container('AddTriplestore');
        
        if (isset($session->processedDeletions) && in_array($itemId, $session->processedDeletions)) {
            error_log("Skipping duplicate deletion event for item $itemId", 3, OMEKA_PATH . '/logs/finalDelete.log');
            return;
        }
        
        if (!isset($session->processedDeletions)) {
            $session->processedDeletions = [];
        }
        
        $session->processedDeletions[] = $itemId;
        

        $settings = $this->getServiceLocator()->get('Omeka\Settings');
        

        $itemDeletionInfo = $settings->get('addtriplestore_item_deletion_info', []);
        
        $identifier = null;
        $graphId = "0";
        
        if (isset($itemDeletionInfo[$itemId])) {
            $info = $itemDeletionInfo[$itemId];
            $identifier = $info['identifier'] ?? null;
            $itemSetIds = $info['itemSetIds'] ?? [];
            
            if (!empty($itemSetIds)) {
                $graphId = $itemSetIds[0];
                error_log("✓ Using pre-deletion item set ID as graph ID: $graphId", 3, OMEKA_PATH . '/logs/finalDelete.log');
            }
            

            unset($itemDeletionInfo[$itemId]);
            $settings->set('addtriplestore_item_deletion_info', $itemDeletionInfo);
        } else {

            $itemItemSetMap = $settings->get('addtriplestore_item_itemset_map', []);
            
            if (isset($itemItemSetMap[$itemId])) {
                $graphId = $itemItemSetMap[$itemId];
                error_log("✓ Using mapped item set ID as graph ID: $graphId", 3, OMEKA_PATH . '/logs/finalDelete.log');
                

                unset($itemItemSetMap[$itemId]);
                $settings->set('addtriplestore_item_itemset_map', $itemItemSetMap);
            } else {
                error_log("⚠ No mapping found for item $itemId, using default graph", 3, OMEKA_PATH . '/logs/finalDelete.log');
            }
        }
        

        if (!$identifier) {

            $identifier = "ITEM-$itemId";
            error_log("⚠ Generated fallback identifier: $identifier", 3, OMEKA_PATH . '/logs/finalDelete.log');
        }
        
        error_log("Final deletion parameters: identifier='$identifier', itemId=$itemId, graphId='$graphId'", 3, OMEKA_PATH . '/logs/finalDelete.log');
        

        $this->deleteFromGraphDB($identifier, $itemId, $graphId);
        

        $this->cleanupProcessedDeletions($session);
    }

    /**
     * This method debugs the contents of a specific graph in GraphDB.
     * It retrieves triples related to a given identifier and logs them.
     * @param mixed $graphUri
     * @param mixed $identifier
     * @return void
     */
    private function debugGraphContents($graphUri, $identifier) {
        error_log("=== DEBUGGING GRAPH CONTENTS ===", 3, OMEKA_PATH . '/logs/finalDelete.log');
        
        $query = "
PREFIX dct: <http://purl.org/dc/terms/>
PREFIX xsd: <http://www.w3.org/2001/XMLSchema#>

SELECT ?s ?p ?o
WHERE {
  GRAPH <$graphUri> {
    {
      # Find all triples related to our identifier
      ?artifact dct:identifier \"$identifier\"^^xsd:literal .
      ?artifact ?p ?o .
      BIND(?artifact AS ?s)
    }
    UNION
    {
      # Find anything that references our artifact
      ?s ?p ?artifact .
      ?artifact dct:identifier \"$identifier\"^^xsd:literal .
      BIND(?artifact AS ?o)
    }
    UNION
    {
      # Find anything with our identifier in the URI
      ?s ?p ?o .
      FILTER(CONTAINS(STR(?s), \"$identifier\"))
    }
  }
}
LIMIT 50";

        try {
            $response = $this->executeSparqlQuery("http://localhost:7200/repositories/megalod/statements", $query);
            $results = json_decode($response->getBody(), true);
            
            if (isset($results['results']['bindings'])) {
                $count = count($results['results']['bindings']);
                error_log("Found $count triples related to '$identifier' in graph $graphUri:", 3, OMEKA_PATH . '/logs/finalDelete.log');
                
                foreach ($results['results']['bindings'] as $binding) {
                    $s = $binding['s']['value'] ?? 'N/A';
                    $p = $binding['p']['value'] ?? 'N/A';
                    $o = $binding['o']['value'] ?? 'N/A';
                    error_log("  Triple: <$s> <$p> <$o>", 3, OMEKA_PATH . '/logs/finalDelete.log');
                }
            } else {
                error_log("No triples found for identifier '$identifier' in graph $graphUri", 3, OMEKA_PATH . '/logs/finalDelete.log');
            }
        } catch (\Exception $e) {
            error_log("Error debugging graph contents: " . $e->getMessage(), 3, OMEKA_PATH . '/logs/finalDelete.log');
        }
    }

    /**
     * This method cleans up old processed deletions from the session.
     * @param mixed $session
     * @return void
     */
    private function cleanupProcessedDeletions($session)
    {

        if (isset($session->processedDeletions) && count($session->processedDeletions) > 100) {
            $session->processedDeletions = array_slice($session->processedDeletions, -100);
        }
    }


    /**
     * This method cleans up old deletion info from the settings.
     * @return void
     */
    private function cleanupOldDeletionInfo() 
    {
        $settings = $this->getServiceLocator()->get('Omeka\Settings');
        $itemDeletionInfo = $settings->get('addtriplestore_item_deletion_info', []);
        
        if (empty($itemDeletionInfo)) {
            return;
        }
        
        $currentTime = time();
        $oneDayAgo = $currentTime - (24 * 60 * 60); 
        
        $modified = false;
        foreach ($itemDeletionInfo as $itemId => $info) {
            if (isset($info['timestamp']) && $info['timestamp'] < $oneDayAgo) {
                unset($itemDeletionInfo[$itemId]);
                $modified = true;
            }
        }
        
        if ($modified) {
            $settings->set('addtriplestore_item_deletion_info', $itemDeletionInfo);
        }
    }

    public function install(ServiceLocatorInterface $serviceLocator)
    {
        $settings = $serviceLocator->get('Omeka\Settings');
        $settings->set('addtriplestore_item_itemset_map', []);
        $settings->set('addtriplestore_item_deletion_info', []);
    }
        

    /**
     * This method deletes triples from GraphDB based on the identifier and item ID.
     * @param mixed $identifier
     * @param mixed $itemId
     * @param mixed $graphId
     * @return void
     */
    private function deleteFromGraphDB($identifier, $itemId, $graphId)
    {
        $graphdbEndpoint = "http://localhost:7200/repositories/megalod/statements";
        $baseDataGraphUri = "https://purl.org/megalod/";
        $graphUri = $baseDataGraphUri . $graphId . "/";
        
        error_log("=== ENHANCED GRAPHDB DELETION ===", 3, OMEKA_PATH . '/logs/finalDelete.log');
        error_log("Deleting from GraphDB: identifier='$identifier', itemId=$itemId, graphUri='$graphUri'", 3, OMEKA_PATH . '/logs/finalDelete.log');
        
        try {

            $this->debugGraphContents($graphUri, $identifier);
            

            $countQuery = $this->buildCountQuery($graphUri, $identifier, $graphId);
            $countResponse = $this->executeSparqlQuery($baseDataGraphUri, $countQuery);
            
            $countData = json_decode($countResponse->getBody(), true);
            $tripleCount = 0;
            
            if ($countData && isset($countData['results']['bindings']) && 
                !empty($countData['results']['bindings'])) {
                $tripleCount = (int)$countData['results']['bindings'][0]['count']['value'];
                error_log("Found $tripleCount triples to delete for identifier '$identifier'", 3, OMEKA_PATH . '/logs/finalDelete.log');
            }
            
            if ($tripleCount == 0) {
                error_log("⚠ WARNING: No triples found to delete for identifier '$identifier' in graph '$graphUri'", 3, OMEKA_PATH . '/logs/finalDelete.log');
                

                $fallbackGraphs = [
                    $baseDataGraphUri . "0/",  
                    $baseDataGraphUri . $itemId . "/",  
                ];
                
                foreach ($fallbackGraphs as $fallbackGraph) {
                    if ($fallbackGraph === $graphUri) continue; 
                    
                    error_log("Trying fallback graph: $fallbackGraph", 3, OMEKA_PATH . '/logs/finalDelete.log');
                    $this->debugGraphContents($fallbackGraph, $identifier);
                    
                    $fallbackCountQuery = $this->buildCountQuery($fallbackGraph, $identifier, $graphId);
                    $fallbackCountResponse = $this->executeSparqlQuery($baseDataGraphUri, $fallbackCountQuery);
                    $fallbackCountData = json_decode($fallbackCountResponse->getBody(), true);
                    
                    if ($fallbackCountData && isset($fallbackCountData['results']['bindings']) && 
                        !empty($fallbackCountData['results']['bindings'])) {
                        $fallbackCount = (int)$fallbackCountData['results']['bindings'][0]['count']['value'];
                        if ($fallbackCount > 0) {
                            error_log("✓ Found $fallbackCount triples in fallback graph $fallbackGraph", 3, OMEKA_PATH . '/logs/finalDelete.log');
                            $graphUri = $fallbackGraph;
                            $tripleCount = $fallbackCount;
                            break;
                        }
                    }
                }
            }
            

            if ($tripleCount > 0) {
                error_log("Proceeding with deletion of $tripleCount triples from $graphUri", 3, OMEKA_PATH . '/logs/finalDelete.log');
                
                $result = $this->deleteResourceByIdentifier($graphdbEndpoint, $graphUri, $identifier, $graphId);
                
                if ($result->isSuccess()) {
                    error_log("✓ SUCCESS: Deleted $tripleCount triples for identifier '$identifier'", 3, OMEKA_PATH . '/logs/finalDelete.log');
                    

                    $verifyCountResponse = $this->executeSparqlQuery("http://localhost:7200/repositories/megalod", $countQuery);
                    $verifyCountData = json_decode($verifyCountResponse->getBody(), true);
                    if ($verifyCountData && isset($verifyCountData['results']['bindings']) && 
                        !empty($verifyCountData['results']['bindings'])) {
                        $remainingCount = (int)$verifyCountData['results']['bindings'][0]['count']['value'];
                        error_log("Verification: $remainingCount triples remaining after deletion", 3, OMEKA_PATH . '/logs/finalDelete.log');
                    }
                } else {
                    error_log("❌ FAILED: Deletion query failed with status: " . $result->getStatusCode(), 3, OMEKA_PATH . '/logs/finalDelete.log');
                    error_log("Response body: " . $result->getBody(), 3, OMEKA_PATH . '/logs/finalDelete.log');
                }
            } else {
                error_log("❌ FAILED: No triples found to delete for identifier '$identifier' in any graph", 3, OMEKA_PATH . '/logs/finalDelete.log');
            }
            
        } catch (\Exception $e) {
            error_log("❌ ERROR: Exception during GraphDB deletion: " . $e->getMessage(), 3, OMEKA_PATH . '/logs/finalDelete.log');
        }
    }

/**
 * This method builds a SPARQL COUNT query to count triples related to an identifier in a specific graph.
 * @param mixed $graphUri
 * @param mixed $identifier
 * @param mixed $graphId
 * @return string
 */
private function buildCountQuery($graphUri, $identifier, $graphId) {
    $itemUri = "https://purl.org/megalod/" . $graphId . "/item/" . $identifier;
    
    return "
PREFIX dct: <http://purl.org/dc/terms/>
PREFIX xsd: <http://www.w3.org/2001/XMLSchema#>

SELECT (COUNT(*) as ?count)
WHERE {
  GRAPH <$graphUri> {
    {
      # Count triples where item URI is subject
      ?s ?p ?o .
      FILTER(STR(?s) = \"$itemUri\" || STRSTARTS(STR(?s), \"$itemUri/\"))
    }
    UNION
    {
      # Count triples where item URI is predicate  
      ?s ?p ?o .
      FILTER(STR(?p) = \"$itemUri\")
    }
    UNION
    {
      # Count triples where item URI is object
      ?s ?p ?o .
      FILTER(STR(?o) = \"$itemUri\" || STRSTARTS(STR(?o), \"$itemUri/\"))
    }
  }
}";
}

    /**
     * This method executes a SPARQL query against the GraphDB endpoint.
     * @param mixed $endpoint
     * @param mixed $query
     * @return \Laminas\Http\Response
     */
    private function executeSparqlQuery($endpoint, $query) {
        $client = new Client();
        $client->setMethod('POST');
        $client->setUri(str_replace('/statements', '', $endpoint));
        $client->setHeaders([
            'Content-Type' => 'application/sparql-query',
            'Accept' => 'application/json'
        ]);
        $client->setRawBody($query);
        
        try {
            $response = $client->send();
            return $response;
        } catch (\Exception $e) {
            error_log("Exception when executing SPARQL query: " . $e->getMessage(), 3, OMEKA_PATH . '/logs/finalDelete.log');
            throw $e;
        }
    }
/*This method deletes a resource from GraphDB using comprehensive patterns.
 * It handles various cases where the item URI can be subject, predicate, or object.
 * @param mixed $endpoint
 * @param mixed $graphUri
 * @param mixed $identifier
 * @param mixed $graphId
 * @return \Laminas\Http\Response
 */
private function deleteResourceByIdentifier($endpoint, $graphUri, $identifier, $graphId)
{
    error_log("=== ENHANCED DELETE WITH COMPREHENSIVE PATTERNS ===", 3, OMEKA_PATH . '/logs/finalDelete.log');
    error_log("Deleting identifier '$identifier' from graph $graphUri", 3, OMEKA_PATH . '/logs/finalDelete.log');
    

    $itemUri = "https://purl.org/megalod/" . $graphId . "/item/" . $identifier;
    
    $query = "
PREFIX rdf: <http://www.w3.org/1999/02/22-rdf-syntax-ns#>
PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>
PREFIX xsd: <http://www.w3.org/2001/XMLSchema#>
PREFIX dbo: <http://dbpedia.org/ontology/>
PREFIX dct: <http://purl.org/dc/terms/>
PREFIX foaf: <http://xmlns.com/foaf/0.1/>
PREFIX crm: <http://www.cidoc-crm.org/cidoc-crm/>
PREFIX crmsci: <http://cidoc-crm.org/extensions/crmsci/>
PREFIX edm: <http://www.europeana.eu/schemas/edm/>
PREFIX geo: <http://www.w3.org/2003/01/geo/wgs84_pos#>
PREFIX schema: <http://schema.org/>
PREFIX ah: <https://purl.org/megalod/ms/ah/>
PREFIX excav: <https://purl.org/megalod/ms/excavation/>
PREFIX wgs: <http://www.w3.org/2003/01/geo/wgs84_pos#>

DELETE {
  GRAPH <$graphUri> {
    ?s ?p ?o .
  }
}
WHERE {
  GRAPH <$graphUri> {
    {
      # Pattern 1: Triples where the item URI is the subject
      ?s ?p ?o .
      FILTER(STR(?s) = \"$itemUri\")
    }
    UNION
    {
      # Pattern 2: Triples where the item URI is the predicate
      ?s ?p ?o .
      FILTER(STR(?p) = \"$itemUri\")
    }
    UNION
    {
      # Pattern 3: Triples where the item URI is the object
      ?s ?p ?o .
      FILTER(STR(?o) = \"$itemUri\")
    }
    UNION
    {
      # Pattern 4: Related sub-resources (encounter events, etc.) 
      # that start with the item URI
      ?s ?p ?o .
      FILTER(STRSTARTS(STR(?s), \"$itemUri/\"))
    }
    UNION
    {
      # Pattern 5: Triples where sub-resources are objects
      ?s ?p ?o .
      FILTER(STRSTARTS(STR(?o), \"$itemUri/\"))
    }
  }
}";

    error_log("ENHANCED SPARQL Delete Query: $query", 3, OMEKA_PATH . '/logs/finalDelete.log');
    error_log("Item URI being deleted: $itemUri", 3, OMEKA_PATH . '/logs/finalDelete.log');
    
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

        error_log("Attempting to delete resource related to Omeka item ID $itemId from graph $graphUri", 3, OMEKA_PATH . '/logs/finalDelete.log');
        


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
        
        error_log("SPARQL Query: $query", 3, OMEKA_PATH . '/logs/finalDelete.log');
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
            

            $statusCode = $response->getStatusCode();
            if ($response->isSuccess()) {
                error_log("GraphDB query executed successfully: Status $statusCode", 3, OMEKA_PATH . '/logs/finalDelete.log');
            } else {
                $errorMsg = "GraphDB query failed: " . $statusCode . " - " . $response->getBody();
                error_log($errorMsg, 3, OMEKA_PATH . '/logs/finalDelete.log');
                throw new \Exception($errorMsg);
            }
            
            return $response;
        } catch (\Exception $e) {
            error_log("Exception when executing SPARQL query: " . $e->getMessage(), 3, OMEKA_PATH . '/logs/finalDelete.log');
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
            error_log("Item $itemId has no item sets", 3, OMEKA_PATH . '/logs/finalDelete.log');
            return;
        }
        

        $settings = $this->getServiceLocator()->get('Omeka\Settings');
        $itemItemSetMap = $settings->get('addtriplestore_item_itemset_map', []);
        
        foreach ($itemSets as $itemSet) {
            $itemSetId = $itemSet->id();
            $itemItemSetMap[$itemId] = $itemSetId;
            error_log("Tracking item $itemId in item set $itemSetId ({$itemSet->title()})", 3, OMEKA_PATH . '/logs/finalDelete.log');
            break; 
        }
        
        $settings->set('addtriplestore_item_itemset_map', $itemItemSetMap);
        error_log("Updated item-itemset mapping in module settings", 3, OMEKA_PATH . '/logs/finalDelete.log');
    }
}