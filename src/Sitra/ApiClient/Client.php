<?php

namespace Sitra\ApiClient;

use GuzzleHttp\Client as BaseClient;
use GuzzleHttp\Command\CommandInterface;
use GuzzleHttp\Command\Exception\CommandClientException;
use GuzzleHttp\Command\Exception\CommandException;
use GuzzleHttp\Command\Exception\CommandServerException;
use GuzzleHttp\Command\Guzzle\Description;
use GuzzleHttp\Command\Guzzle\GuzzleClient;
use GuzzleHttp\Command\ResultInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\HandlerStack;
use Sitra\ApiClient\Description\Agenda;
use Sitra\ApiClient\Description\Exports;
use Sitra\ApiClient\Description\Metadata;
use Sitra\ApiClient\Description\Reference;
use Sitra\ApiClient\Description\Search;
use Sitra\ApiClient\Description\Sso;
use Sitra\ApiClient\Description\TouristicObjects;
use Sitra\ApiClient\Description\User;
use Sitra\ApiClient\Description\Member;
use Sitra\ApiClient\Description\Edit;
use Sitra\ApiClient\Exception\InvalidExportDirectoryException;
use Sitra\ApiClient\Exception\InvalidMetadataFormatException;
use Sitra\ApiClient\Exception\SitraException;
use Sitra\ApiClient\Subscriber\ApidaeSubscriber;
use Sitra\ApiClient\Traits\Debug;
use Sitra\ApiClient\Traits\Export;
use Sitra\ApiClient\Traits\Sso as ApidaeSso;

/**
 * Magic operations:
 *
 * @method array getObjectById() getObjectById(array $params)
 * @method array getObjectByIdentifier() getObjectByIdentifier(array $params)
 *
 * @method array getMetadata() getMetadata(array $params)
 * @method array deleteMetadata() deleteMetadata(array $params)
 * @method array putMetadata() putMetadata(array $params)
 * 
 * @method array confirmExport() confirmExport(array $params)
 *
 * @method array searchObject() searchObject(array $params)
 * @method array searchObjectIdentifier() searchObjectIdentifier(array $params)
 *
 * @method array searchAgenda() searchAgenda(array $params)
 * @method array searchAgendaIdentifier() searchAgendaIdentifier(array $params)
 * @method array searchDetailedAgenda() searchDetailedAgenda(array $params)
 * @method array searchDetailedAgendaIdentifier() searchDetailedAgendaIdentifier(array $params)
 *
 * @method array getReferenceCity() getReferenceCity(array $params)
 * @method array getReferenceElement() getReferenceElement(array $params)
 * @method array getReferenceInternalCriteria() getReferenceInternalCriteria(array $params)
 * @method array getReferenceSelection() getReferenceSelection(array $params)
 *
 * @method array getSsoToken() getSsoToken(array $params)
 * @method array refreshSsoToken() refreshSsoToken(array $params)
 *
 * @method array getUserProfile() getUserProfile()
 * @method array getUserPermissionOnObject() getUserPermissionOnObject(array $params)
 * 
 * @method array getMemberById() getMemberById(array $params)
 * @method array getMembers() getMembers(array $params)
 * @method array getUserById() getUserById(array $params)
 * @method array getUserByMail() getUserByMail(array $params)
 * @method array getUserByMember() getUserByMember(array $params)
 * @method array getAllUsers() getAllUsers(array $params)
 *
 * @method array getEditAutorisation() getEditAutorisation(array $params)
 *
 */
class Client extends GuzzleClient
{
  use Export;
  use ApidaeSso;

  const META_SCOPE = 'api_metadonnees';
  const SSO_SCOPE  = 'sso';
  const EDIT_SCOPE  = 'api_ecriture';

  protected $config = [
    'baseUri'       => 'https://api.apidae-tourisme.com/',
    'apiKey'        => null,
    'projetId'      => null,

    // Auth for metadata
    'OAuthClientId' => null,
    'OAuthSecret'   => null,

    // Export
    'exportDir'     => '/tmp/sitraExports/',

    // For object lists
    'responseFields' => [],
    'locales'        => [],
    'count'          => 20,

    // For HTTP Client
    'timeout'           => 0,
    'connectTimeout'    => 0,
    'proxy'             => null,
    'verify'            => true,

    // For SSO authentication
    'ssoBaseUrl'        => 'https://base.apidae-tourisme.com',
    'ssoRedirectUrl'    => 'http://localhost/',
    'ssoClientId'       => null,
    'ssoSecret'         => null,

    // Access tokens by scope
    'accessTokens'      => [],
  ];

  public $operations;

  /**
   * @param array $config
   * @param Description $description
   */
  public function __construct(array $config = [], Description $description = null)
  {
    if ($description === null) {
      $operations = array_merge(
        TouristicObjects::$operations,
        Metadata::$operations,
        Exports::$operations,
        Search::$operations,
        Agenda::$operations,
        Reference::$operations,
        Sso::$operations,
        User::$operations,
        Member::$operations,
        Edit::$operations
      );

      $descriptionData = [
        'baseUri' => $this->config['baseUri'],
        'operations' => $operations,
        'models' => [
          'getResponse' => [
            'type' => 'object',
            'additionalProperties' => [
              'location' => 'json'
            ]
          ],
          'getResponseBody' => [
            'type' => 'object',
            'properties' => [
              'response' => [
                'location' => 'body',
                'type' => 'string'
              ]
            ]
          ]
        ]
      ];

      $description = new Description($descriptionData);
    }

    $this->operations = $operations;

    if (isset($config['projectId'])) $config['projetId'] = $config['projectId'];

    if (isset($config['ssoToken'])) {
      $config['accessTokens'][self::SSO_SCOPE] = $config['ssoToken']['access_token'];
      unset($config['ssoToken']);
    }

    $this->config = new \ArrayObject(array_merge($this->config, $config));

    if (isset($this->config['handler']) === false) {
      $this->config['handler'] = HandlerStack::create();
    }

    $config = array_filter([
      'base_uri' => $this->config['baseUri'],
      'timeout'           => $this->config['timeout'],
      'connect_timeout'   => $this->config['connectTimeout'],
      'proxy'             => $this->config['proxy'],
      'verify'            => $this->config['verify'],
      'handler'           => $this->config['handler'],
    ]);

    $client = new BaseClient($config);

    parent::__construct($client, $description, new ApidaeSerializer($description, $client, $this, []));

    $stack = $this->getHandlerStack();
    $stack->before('validate_description', new ApidaeSubscriber($description, $this));
  }

  /**
   * Execute a single command.
   *
   * @param CommandInterface $command Command to execute
   *
   * @return ResultInterface The result of the executed command
   * @throws CommandException
   */
  public function execute(CommandInterface $command): ResultInterface
  {
    try {
      return $this->executeAsync($command)->wait();
    } catch (\Exception $e) {
      $this->handleHttpError($e);
    }
  }

  /**
   * @param \Exception $e
   * @throws SitraException
   * @throws \Exception
   */
  private function handleHttpError(\Exception $e)
  {
    if ($e->getPrevious() instanceof InvalidMetadataFormatException) {
      throw $e->getPrevious();
    }
    if ($e instanceof CommandClientException) {
      throw new SitraException($e);
    }

    if ($e instanceof CommandServerException) {
      throw new SitraException($e);
    }

    if ($e instanceof CommandException) {
      throw $e;
    }

    if ($e instanceof RequestException) {
      throw new SitraException($e);
    }

    throw $e;
  }

  /**
   * @todo n'autoriser la récupération que des clés utiles ailleurs pour éviter toute erreur
   * @todo  Voir s'il vaut mieux lancer une erreur au lieu du return false... ?
   * @param string  nom de la variable de conf recherchée (ex: ssoBaseUrl)
   * @return  string|array  Valeur de la variable de conf (ex: https://base.apidae-tourisme.com)
   */
  public function config(string $var)
  {
    if (isset($this->config[$var]))
      return $this->config[$var];
    else
      return false;
  }
}
