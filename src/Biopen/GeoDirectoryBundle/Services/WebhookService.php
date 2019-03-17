<?php

namespace Biopen\GeoDirectoryBundle\Services;

use Biopen\CoreBundle\Document\Configuration;
use Biopen\CoreBundle\Document\ConfImage;
use Biopen\CoreBundle\Document\User;
use Biopen\GeoDirectoryBundle\Document\Webhook;
use Biopen\GeoDirectoryBundle\Document\WebhookAction;
use Biopen\GeoDirectoryBundle\Document\WebhookFormat;
use Biopen\GeoDirectoryBundle\Document\WebhookPost;
use Biopen\GeoDirectoryBundle\Document\Element;
use Biopen\GeoDirectoryBundle\Document\InteractionType;
use Doctrine\ODM\MongoDB\DocumentManager;
use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Response;
use http\Exception\InvalidArgumentException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Router;
use Symfony\Component\Security\Core\SecurityContext;
use Biopen\GeoDirectoryBundle\Document\UserInteractionContribution;

class WebhookService
{
	protected $em;

	protected $router;

    public function __construct(DocumentManager $documentManager, Router $router, SecurityContext $securityContext, $baseUrl, $basePath)
    {
    	 $this->em = $documentManager;
    	 $this->router = $router;
         $this->securityContext = $securityContext;
         $this->baseUrl = 'http://' . $baseUrl . $basePath;
         $this->config = $this->em->getRepository(Configuration::class)->findConfiguration();
    }

    /**
     * @param WebhookPost[] $webhookPosts
     */
    public function processPosts($limit = 5)
    {
        $contributions = $this->em->createQueryBuilder(UserInteractionContribution::class)
        ->field('status')->exists(true)
        ->field('webhookPosts.nextAttemptAt')->lte(new \DateTime())        
        ->limit($limit)
        ->getQuery()->execute();

        if (!$contributions || $contributions->count() == 0) return 0;

        $client = new Client();
        $contributionsToProceed = [];
        $postsToProceed = [];

        // PREPARE EACH POST (calculate data, url...)
        foreach ($contributions as $contribution) 
        {          
            $data = $this->calculateData($contribution);
            foreach($contribution->getWebhookPosts() as $webhookPost) 
            {
                if (!$webhookPost->getStatus()) 
                {
                    $webhook = $webhookPost->getWebhook();
                    $webhookPost->setUrl($webhook->getUrl());                   
                    $jsonData = json_encode($this->formatData($webhook->getFormat(), $data));
                    $webhookPost->setData($jsonData);
                    $postsToProceed[] = $webhookPost;
                    $contributionsToProceed[] = $contribution;
                }                    
            }  
        }      

        // CREATE POST REQUESTS
        $requests = function() use($client, $postsToProceed) {
            foreach($postsToProceed as $post) yield new \GuzzleHttp\Psr7\Request('POST', $post->getUrl() , [], $post->getData() );
        };        

        // SEND REQUEST CONCURRENTLY AND HANDLE RESULTS
        $pool = new Pool($client, $requests(), [
            'concurrency' => 5,
            'fulfilled' => function (Response $response, $index) use ($postsToProceed, $contributionsToProceed) {
                $post = $postsToProceed[$index];
                $contribution = $contributionsToProceed[$index];
                $contribution->removeWebhookPost($post);
            },
            'rejected' => function ($reason, $index) use ($postsToProceed, $contributionsToProceed) {
                $post = $postsToProceed[$index];
                $attemps = $post->incrementNumAttempts();
                if ($attemps < 6) {
                    // After first try, wait 5m, 25m, 2h, 10h, 2d 
                    $intervalInMinutes = pow(5, $attemps); 
                    $interval = new \DateInterval("PT{$intervalInMinutes}M"); 
                    $now = new \DateTime();
                    $post->setNextAttemptAt($now->add($interval));
                } else {                    
                    $post->setStatus('failed');
                    $post->setNextAttemptAt(new \DateTime('3000-01-01'));
                }                
            },
        ]);

        // Initiate the transfers and create a promise
        $promise = $pool->promise();
        // Force the pool of requests to complete.
        $promise->wait();

        $this->em->flush();

        return count($postsToProceed);
    }

    private function calculateData($contribution)
    {
        // STANDRD CONTIRBUTION
        if ($contribution->getElement()) 
        {
            $element = $contribution->getElement();
            $this->em->refresh($element);
            $element->setPreventJsonUpdate(true);
            $link = str_replace('%23', '#', $this->router->generate('biopen_directory_showElement', array('id'=>$element->getId()), true));
            $data = json_decode($element->getBaseJson(), true);
        }
        // BATCH CONTRIBUTION
        else
        {
            $link = "";
            $data = ['ids' => $contribution->getElementIds()];
        }

        $mappingType = [InteractionType::Deleted => 'delete', InteractionType::Add => 'add',     InteractionType::Edit => 'edit', 
                        InteractionType::Import => 'add',     InteractionType::Restored => 'add'];
        $result = [
            'action' => $mappingType[$contribution->getType()],
            'user' => $contribution->getUserDisplayName(),
            'link' => $link,
            'data' => $data
        ];
        $result['text'] = $contribution->getElement() ? $this->getNotificationText($result) : $this->getBatchNotificationText($result);
        return $result;
    }

    private function getNotificationText($result)
    {
        $element = $this->config->getElementDisplayName();
        switch($result['action']) {
            case 'add':
                return "**AJOUT** {$element} **{$result['data']['name']}** ajouté par {$result['user']}\n[Lien vers la fiche]({$result['link']})";
            case 'edit':
                return "**MODIFICATION** {$element} **{$result['data']['name']}** mis à jour par *{$result['user']}*\n[Lien vers la fiche]({$result['link']})";
            case 'delete':
                return "**SUPPRESSION** {$element} **{$result['data']['name']}** supprimé par *{$result['user']}*";
            default:
                throw new InvalidArgumentException(sprintf('The webhook action "%s" is invalid.', $result['action']));
        }
    }

    protected $transTitle = [ 'add' => 'AJOUT', 'edit' => 'MODIFICATION', 'delete' => 'SUPPRESSION'];
    protected $transText = [ 'add' => 'ajoutés', 'edit' => 'mis à jour', 'delete' => 'supprimés'];

    private function getBatchNotificationText($result)
    {
        $elements = $this->config->getElementDisplayNamePlural();
        $title = $this->transTitle[$result['action']];
        $text = $this->transText[$result['action']];
        $count = count($result['data']['ids']);
        return "**{$title}** {$count} {$elements} {$text} par {$result['user']}";
    }

    private function getBotIcon()
    {       
        /** @var ConfImage $img */
        $img = $this->config->getFavicon() ? $this->config->getFavicon() : $this->config->getLogo();

        return $img
            ? $img->getImageUrl()
            : str_replace('app_dev.php/', '', $this->baseUrl . '/assets/img/default-icon.png');
    }

    private function formatData($format, $data)
    {
        switch($format) {
            case WebhookFormat::Raw:
                return $data;

            case WebhookFormat::Mattermost:
                return [
                    "username" => $this->config->getAppName(),
                    "icon_url" => $this->getBotIcon(),
                    "text" => $data['text']
                ];

            case WebhookFormat::Slack:
                return ["text" => $data['text']];

            default:
                throw new InvalidArgumentException(sprintf('The webhook format "%s" is invalid.', $format));
        }
    }
}
