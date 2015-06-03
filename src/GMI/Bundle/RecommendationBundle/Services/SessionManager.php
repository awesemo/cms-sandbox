<?php

namespace GMI\Bundle\RecommendationBundle\Services;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\SecurityContextInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use GMI\Bundle\RecommendationBundle\Model\CloudableInterface;
use GMI\Bundle\RecommendationBundle\Model\CloudInterface;
use GMI\Bundle\RecommendationBundle\Entity\Manager\CloudManagerInterface;
use GMI\Bundle\RecommendationBundle\Entity\Manager\CloudCategoryManagerInterface;
use GMI\Bundle\RecommendationBundle\Entity\Manager\CloudLogsManagerInterface;

class SessionManager
{
	const COOKIE_NAME             = "cloud-uid";
	const CLOUD_CATEGORY_KEY      = "categories";
	const CLOUD_CATEGORY_ROOT_KEY = "categories_root";
	const CLOUD_SURVEY_KEY        = "survey";
	const BASE_CATEGORY_WEIGHT    = 1;

    protected $config;
    protected $session;
    protected $request;
    protected $securityContext;
    protected $cloudManager;
    protected $cloudCategoryManager;
    protected $cloudLogsManager;

	protected $container;
	protected $cloud;
	protected $data;
	protected $dataKeyValueProcessed;

    /**
     * Default constructor
     *
     * @param SessionInterface $session
     * @param Request|RequestStack $request
     * @param Config $config
     * @param SecurityContextInterface $securityContext
     * @param CloudManagerInterface $cloudManager
     * @param CloudCategoryManagerInterface $cloudCategoryManager
     */
    public function __construct(SessionInterface $session,
                                RequestStack $request,
                                Config $config,
                                SecurityContextInterface $securityContext,
                                CloudManagerInterface $cloudManager,
                                CloudCategoryManagerInterface $cloudCategoryManager,
                                CloudLogsManagerInterface $cloudLogsManager)
    {

        # Recommendation Config
        $this->config = $config;
        # Session
        $this->session = $session;
        # Request
        $this->request = $request;
        # Security Context
        $this->securityContext = $securityContext;
        # Cloud Manager
        $this->cloudManager = $cloudManager;
        # Cloud Category Manager
        $this->cloudCategoryManager = $cloudCategoryManager;
        # Cloud Logs Manager
        $this->cloudLogsManager = $cloudLogsManager;
		# category cloud
		$this->cloud      = null;
		# category cloud weight mapping container
		$this->data       = array(self::CLOUD_CATEGORY_KEY => array());
		// populate category cloud mapping
		$this->loadCloudCategories();
		// container to make sure categories are processed only once per request.
		$this->dataKeyValueProcessed = array();
    }
    
    /**
     * Link cloud to 

    /**
     * Return the current cloud
     *
     * @return CloudInterface
     */
	public function getCloud()
	{
		return $this->cloud;
	}
	
	/**
	 * Profiling configuration wrapper
	 * 
	 * @return \GMI\Bundle\RecommendationBundle\Services\Config
	 */
	public function getConfig()
	{
		return $this->getConfigService();
	}
    
	/**
	 * Load the category config array mapping
	 * 
	 * @return null
	 */
    public function loadCloudCategories()
    {
		if (($cloudCategories = $this->getCloudCategoryManagerService()->getActiveCloudCategories())) {
			foreach ($cloudCategories as $cloudCategory) {
				$this->data[self::CLOUD_CATEGORY_KEY][$cloudCategory['id']] = array('priority' => (int)$cloudCategory['priority'], 'event' => $cloudCategory['slug']);
			}
		}
	}
	
	/**
	 * Check if category is in category cloud config
	 * category cloud config holds the category weight. This can be
	 * defined in the backend
	 * 
	 * @param string $key
	 * @return boolean
	 */
	protected function isCategoryCloud($key)
	{
		if (isset($this->data[self::CLOUD_CATEGORY_KEY][$key])) {
			return true;
		}
		return false;
	}

    protected function hasPriorityEvent($id , $event = 'view') {
        if (isset($this->data[self::CLOUD_CATEGORY_KEY][$id])) {
            if(isset($this->data[self::CLOUD_CATEGORY_KEY][$id]['event'])) {
                return ($this->data[self::CLOUD_CATEGORY_KEY][$id]['event'] === $event) ? true : false;
            }
        }
        return false;
    }
	
	/**
	 * Get the category priority value
	 * 
	 * @param integer $id
	 * @return float
	 */
	protected function getCategoryPriorityValue($id)
	{
		if (isset($this->data[self::CLOUD_CATEGORY_KEY][$id])) {
			return (float) (isset($this->data[self::CLOUD_CATEGORY_KEY][$id]['priority'])?$this->data[self::CLOUD_CATEGORY_KEY][$id]['priority']:0);
		}
		return 0;
	}

    /**
     * Get the category priority value
     *
     * @param integer $id
     * @return float
     */
    protected function getPriorityValue($id)
    {
        if (isset($this->data[self::CLOUD_CATEGORY_KEY][$id])) {
            return (float) (isset($this->data[self::CLOUD_CATEGORY_KEY][$id]['priority'])?$this->data[self::CLOUD_CATEGORY_KEY][$id]['priority']:0);
        }
        return 0;
    }
    
	/**
	 * Load current cloud usually called in the kernel request event
	 * 
	 * @return \GMI\Bundle\RecommendationBundle\Entity\CloudInterface
	 */
    public function loadCloud()
    {
		if (!$this->getConfig()->isEnabled()) {
			return false;
		}
		
		$request = $this->getRequestStackService()->getCurrentRequest();
		$this->referer = $request->headers->get('referer');
		
		if ($this->cloud) {
			return $this->cloud;
		}
		
		if ($this->isUserLoggedIn()) {
			$this->cloud = $this->getUserCloud();
		} else {
			$this->cloud = $this->getSessionCloud();
		}

		return $this->cloud;
	}
	
	/**
	 * Update cloud, usually this is called in the kernel response event
	 * 
	 * @param \Symfony\Component\HttpFoundation\Response $response
	 */
	public function updateCloud(Response $response = null)
	{
		$request      = $this->getRequestStackService()->getCurrentRequest();
		$enabled      = $this->getConfig()->isEnabled();
		$allowedRoute = $this->getConfig()->isAllowedInRoute($request->get('_route'));
		
		if (!($enabled && $allowedRoute)) {
			return false;
		}
		
		if (!$this->cloud) {
			return;
		}
		
		$cloud = $this->cloud;
		$cookieName = $this->getKeyName();
		if ($cloud) {
			if (!($uid = $this->getRequestStackService()->getCurrentRequest()->cookies->get($cookieName))) {
				// create the cloud cookie if not exist
				$cookie = new Cookie($cookieName, $cloud->getUid(), $this->getCookieLifetime());
				if (!$response) {
					$response = new Response();
				}
				$response->headers->setCookie($cookie);
			}
			if ($this->hasCloudDataForUpdate()) {
				// persist cloud to storage
				$cloudManager = $this->getCloudManagerService();
				$cloudManager->save($cloud);
			}
		}
	}
	
	public function linkSessionCloudToUser(UserInterface $user)
	{
		$enabled = $this->getConfig()->isEnabled();
		if ($enabled && ($cloud = $this->cloud)) {
			if (!$cloud->getUser()) {
				$cloudManager = $this->getCloudManagerService();
				$user = $this->getLoggedInUser();
				$cloud->setUser($user);
				$cloud->setUid(null);
				$cloudManager->save($cloud);
			}
		}
	}
	
	/**
	 * Return the current category cloud data
	 * 
	 * @return array
	 */
	public function getCategoryCloudData()
	{
		$sorted = array();
		if ($this->cloud) {
			$sorted = $this->cloud->getData();
			if (isset($sorted[self::CLOUD_CATEGORY_KEY])) {
				$sorted = $sorted[self::CLOUD_CATEGORY_KEY];
			}
			arsort($sorted, SORT_NUMERIC);
		}
		return $sorted;
	}
	
	/**
	 * Return the current root category cloud data
	 * 
	 * @return array
	 */
	public function getRootCategoryCloudData()
	{
		$sorted = array();
		if ($this->cloud) {
			$sorted = $this->cloud->getData();
			if (isset($sorted[self::CLOUD_CATEGORY_ROOT_KEY])) {
				$sorted = $sorted[self::CLOUD_CATEGORY_ROOT_KEY];
			}
			arsort($sorted, SORT_NUMERIC);
		}
		return $sorted;
	}
	
	/**
	 * Get the current user profile cloud
	 * 
	 * @return \GMI\Bundle\RecommendationBundle\Entity\CloudInterface
	 */
	protected function getUserCloud()
	{
		$user = $this->getLoggedInUser();
		if (($cloud = $this->getCloudManagerService()->findOneBy(array('user' => $user)))) {
			return $cloud;
		} else {
			return $this->rebuildUserCloud($user);
		}
	}
	
	/**
	 * Get the current session cloud based from the cookie uid found
	 * or rebuild the cloud if not found.
	 * 
	 * @return \GMI\Bundle\RecommendationBundle\Entity\CloudInterface
	 */
	protected function getSessionCloud()
	{
		$cookieName = $this->getKeyName();
		if (!($uid = $this->getRequestStackService()->getCurrentRequest()->cookies->get($cookieName))) {
			$newUid = $this->generateToken();
			$cloud  = $this->initCloud($newUid);
		} else {
			$cloud = $this->getCloudManagerService()->findOneBy(array('uid' => $uid));
			if (!$cloud) {
				$cloud = $this->rebuildCloud($uid);
			}
		}
		return $cloud;
	}
	
	/**
	 * Init cloud
	 * 
	 * @param string $uid
	 * @return \GMI\Bundle\RecommendationBundle\Entity\CloudInterface
	 */
	protected function initCloud($uid)
	{
		$cloudManager = $this->getCloudManagerService();
		$cloud = $cloudManager->create();
		$cloud->setUid($uid);
		return $cloud;
	}
	
	/**
	 * Rebuild the db cloud using the uid
	 * 
	 * @param string $uid
	 * @return \GMI\Bundle\RecommendationBundle\Entity\CloudInterface
	 */
	protected function rebuildCloud($uid)
	{
		$cloudManager = $this->getCloudManagerService();
		$cloud = $cloudManager->create();
		$cloud->setUid($uid);
		$cloudManager->save($cloud);
		return $cloud;
	}

	/**
	 * Rebuild user cloud
	 * 
	 * @param UserInterface $user
	 * @return \GMI\Bundle\RecommendationBundle\Entity\CloudInterface
	 */
	protected function rebuildUserCloud($user)
	{
		$cloudManager = $this->getCloudManagerService();
		$cloud = $cloudManager->create();
		$cloud->setUser($user);
		$cloudManager->save($cloud);
		return $cloud;
	}

    /**
     * Update cloud data
     *
     * @param $cloudData
     * @param string $event
     * @return null
     */
    public function cloudData($cloudData, $request, $event = 'view')
    {
        if (!$this->getConfig()->isEnabled()) {
            return false;
        }

        if (($cloud = $this->cloud)) {
            if(is_array($cloudData)) {
                foreach($cloudData as $cData) {
                    $data = $cloud->getData();
                    if (empty($cData)) {
                        continue;
                    }
                    if($this->hasPriorityEvent($cData, $event)) {
                        $this->updateCloudData($cData, $event, $data);
                    }
                }
            } else {
                $data = $cloud->getData();
                if($this->hasPriorityEvent($cloudData, $event)) {
                    $this->updateCloudData($cloudData, $event, $data);
                }
            }
        }
    }

    protected function updateCloudData($cData, $event,  &$data) {
        if (($cloud = $this->cloud)) {
            $data = $cloud->getData();
            if (!($data)) {
                if (!isset($data[self::CLOUD_CATEGORY_KEY])) {
                    $data[self::CLOUD_CATEGORY_KEY] = array();
                }
            }
            $value = self::BASE_CATEGORY_WEIGHT;
            if ($this->isCategoryCloud($cData)) {
                $value += (float)$this->getPriorityValue($cData);
            } else {
                $value += (float)$this->getCategoryPriorityValue($cData);
            };

            $temp = $this->updateDataKeyValue($cData, $value, $data[self::CLOUD_CATEGORY_KEY]);
            $data = array(self::CLOUD_CATEGORY_KEY=>$temp);
            $this->logCloudData($cData, $value, $event, $this->cloud);
            $this->cloud->setData($data);
        }
    }

    protected function logCloudData($cData, $value, $event, $cloud) {

        $logs = $this->cloudLogsManager->create();
        $logs->setCloud($cloud);
        $logs->setCategory($cData);
        $logs->setEvent($event);
        $logs->setPoints($value);
        $this->cloudLogsManager->save($logs);
    }

    public function updateDataKeyValue($cData, $value, $data)
    {
        if($data) {
            if(array_key_exists($cData, $data)) {
                $data[$cData] = $data[$cData]+=$value;
            } else {
                $data[$cData] = $value;
            }
        } else {
            $data = array($cData=>$value);
        }

        return $data;
    }

    /**
     * Check if category is in category cloud config
     * category cloud config holds the category weight. This can be
     * defined in the backend
     *
     * @param string $key
     * @return boolean
     */
    protected function isCloudData($key)
    {
        if (isset($this->data[self::CLOUD_CATEGORY_KEY][$key])) {
            return true;
        }
        return false;
    }

    /**
     * Update cloud data starting from the current category
     * traversing all parent categories and applying the priority values
     * based from the defined cloud category.
     *
     * @param \GMI\Bundle\RecommendationBundle\Entity\CloudableInterface|CloudableInterface $category
     * @param object $refCategory
     * @param array $data
     * @return null
     */
//	public function updateCategoryCloudData(CloudableInterface $category, $refCategory = null, &$data = null)
//	{
//		if (!$this->getConfig()->isEnabled()) {
//			return false;
//		}
//		if (($cloud = $this->cloud)) {
//
//			if (!($data)) {
//				$data = $cloud->getData();
//				if (!isset($data[self::CLOUD_CATEGORY_KEY])) {
//					$data[self::CLOUD_CATEGORY_KEY] = array();
//				}
//				$data = $data[self::CLOUD_CATEGORY_KEY];
//			}
//
//			$key = $category->getId();
//
//			if (empty($key)) {
//				return;
//			}
//
//			$value = self::BASE_CATEGORY_WEIGHT;
//			// check if current category is defined in the category cloud config
//			if ($this->isCategoryCloud($key)) {
//				// add defined priority value for this category
//				$value += (float)$this->getCategoryPriorityValue($key);
//				// we need to pass this category as our reference category in case
//				// our parent categories are not yet defined in the category cloud config
//				$refCategory = $category;
//				// update the cloud data using the derived value
//				$this->updateDataKeyValue($data);
//			} else {
//				// basically this parent category has not been defined in the category cloud config,
//				// use the passed child reference category priority value instead
//				//if ($refCategory && $this->isCategoryCloud($refCategory->getId())) {
//				if ($refCategory && $refCategory->getId()) {
//					$value += (float)$this->getCategoryPriorityValue($refCategory->getId());
//				}
//				$this->updateDataKeyValue($data, $key, $value, ($category->getParent()==null?true:false));
//			}
//
//			// make sure we traverse all parent categories up to its root
//			if ($category->getParent()) {
//				$this->updateCategoryCloudData($category->getParent(),$refCategory, $data);
//			}
//		}
//	}
	
	/**
	 * Update data key value pair
	 * 
	 * @param array $data
	 * @param string $key
	 * @param float $value
	 */
//	public function updateDataKeyValue(&$data, $key, $value, $isRootCategory)
//	{
//		if (isset($data[$key])) {
//			$data[$key] += $value;
//		} else {
//			$data[$key] = $value;
//		}
//		$cloudData = $this->cloud->getData();
//		$cloudData[self::CLOUD_CATEGORY_KEY] = $data;
//		if ($isRootCategory) {
//			$cloudData[self::CLOUD_CATEGORY_ROOT_KEY][$key] = $data[$key];
//		}
//		$this->cloud->setData($cloudData);
//	}
	
	/**
	 * Check if there is a need to persist the category cloud
	 * 
	 * @return boolean
	 */
	protected function hasCloudDataForUpdate()
	{
		if (($cloud = $this->cloud)) {
			$data = $cloud->getData();
			return count($data);
		}
		return false;
	}
	
	/**
	 * Get default key name
	 * 
	 * @return integer
	 */
	protected function getKeyName()
	{
		return sprintf("%s-%s", $this->getSessionService()->getName(), self::COOKIE_NAME);
	}
	
	/**
	 * Default cookie lifetime (1 Week)
	 * 
	 * @return integer
	 */
	protected function getCookieLifetime()
	{
		return (time() + 3600 * 24 * 7);
	}
	
	/**
	 * Generate unique cloud token
	 * 
	 * @param string $seed
	 * @return string
	 */
	public static function generateToken($seed = '')
	{
		$seed = empty($seed) ? microtime() : $seed ;
		$bytes = hash('sha256', sprintf("%s:%s",uniqid(mt_rand(), true),$seed), true);
		return base_convert(bin2hex($bytes), 16, 36);
	}
	
	/**
	 * @return \GMI\Bundle\RecommendationBundle\Services\Config
	 */
	protected function getConfigService()
	{
		return $this->config;
	}
	
	/**
	 * @return \Symfony\Component\HttpFoundation\Session\Session
	 */
	protected function getSessionService()
	{
		return $this->session;
	}
	
	/**
	 * @return \Symfony\Component\HttpFoundation\RequestStack
	 */
	protected function getRequestStackService()
	{
		return $this->request;
	}
	
	/**
	 * @return \Symfony\Component\Security\Core\SecurityContextInterface
	 */
	protected function getSecurityContextService()
	{
		return $this->securityContext;
	}
	
	/**
	 * @return \GMI\Bundle\RecommendationBundle\Entity\Manager\CloudManagerInterface
	 */
	protected function getCloudManagerService()
	{
		return $this->cloudManager;
	}
	
	/**
	 * @return \GMI\Bundle\RecommendationBundle\Entity\Manager\CloudCategoryManagerInterface
	 */
	protected function getCloudCategoryManagerService()
	{
		return $this->cloudCategoryManager;
	}
	
	/**
	 * Check if user is currently logged in
	 * 
	 * @return boolean
	 */
	protected function isUserLoggedIn()
	{
		if ($this->getSecurityContextService()->getToken()) {
			return (bool)$this->getSecurityContextService()->isGranted('IS_AUTHENTICATED_REMEMBERED');
		}
		return false;
	}
	
	/**
	 * 
	 * @return \Sonata\UserBundle\Model\UserInterface
	 */
	protected function getLoggedInUser()
	{
		return $this->getSecurityContextService()->getToken()->getUser();
	}
}
