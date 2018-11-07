<?php

class Configuration {
    private $items = array();

    public function add($id) {
        $result = new DomainConfiguration();
        $result->id = $id;
        array_push($this->items, $result);
        return $result;
    }

    public function getDomainConfig($domain) {
        foreach ($this->items as $domainConfig) {
            if (in_array($domain, $domainConfig->domains)) {
                return $domainConfig;
            }
        }

        throw new Exception('No configuration found for requested domain.');
    }
}

class DomainConfiguration {
    public $domains;
    public $servers = array();
    public $username;

    public function addServer($type, $hostname) {
        $server = $this->createServer($type, $hostname);
        $server->username = $this->username;
        array_push($this->servers, $server);
        return $server;
    }

    private function createServer($type, $hostname) {
        switch ($type) {
            case 'imap':
                return new Server($type, $hostname, 143, 993);
            case 'pop3':
                return new Server($type, $hostname, 110, 995);
            case 'smtp':
                return new Server($type, $hostname, 25, 465);
            case 'activesync':
                return new Server($type, $hostname, 80, 443);
            default:
                throw new Exception("Unrecognized server type \"$type\"");
        }
    }
}

class Server {
    public $type;
    public $hostname;
    public $username;
    public $endpoints;
    public $samePassword;

    public function __construct($type, $hostname, $defaultPort, $defaultSslPort) {
        $this->type = $type;
        $this->hostname = $hostname;
        $this->defaultPort = $defaultPort;
        $this->defaultSslPort = $defaultSslPort;
        $this->endpoints = array();
        $this->samePassword = true;
    }

    public function withUsername($username) {
        $this->username = $username;
        return $this;
    }

    public function withDifferentPassword() {
        $this->samePassword = false;
        return $this;
    }

    public function withEndpoint($socketType, $port = null, $authentication = 'password-cleartext') {
        if ($port === null) {
            $port = $socketType === 'SSL' ? $this->defaultSslPort : $this->defaultPort;
        }

        array_push($this->endpoints, (object)array(
            'socketType' => $socketType,
            'port' => $port,
            'authentication' => $authentication));

        return $this;
    }
}

interface UsernameResolver {
    public function findUsername($request);
}

class AliasesFileUsernameResolver implements UsernameResolver {
    private $fileName;

    function __construct($fileName = "/etc/mail/aliases") {
        $this->fileName = $fileName;
    }

    public function findUsername($request) {
        static $cachedEmail = null;
        static $cachedUsername = null;

        if ($request->email === $cachedEmail) {
            return $cachedUsername;
        }

        $fp = fopen($this->fileName, 'rb');

        if ($fp === false) {
            throw new Exception("Unable to open aliases file \"$fileName\"");
        }

        $username =  $this->findLocalPart($fp, $request->localpart);
        if (strpos($username, "@") !== false || strpos($username, ",") !== false) {
            $username = null;
        }

        $cachedEmail = $request->email;
        $cachedUsername = $username;
        return $username;
    }

    protected function findLocalPart($fp, $localPart) {
        while (($line = fgets($fp)) !== false) {
            $matches = array();
            if (!preg_match("/^\s*" . preg_quote($localPart) . "\s*:\s*(\S+)\s*$/", $line, $matches)) continue;
            return $matches[1];
        }
    }
}

abstract class RequestHandler {
    public function handleRequest() {
        $request = $this->parseRequest();
        $this->expandRequest($request);
        $config = $this->getDomainConfig($request);
        $this->writeResponse($config, $request);
    }

    protected abstract function parseRequest();
    protected abstract function writeResponse($config, $request);

    protected function expandRequest($request) {
        list($localpart, $domain) = explode('@', $request->email);

        if (!isset($request->localpart)) {
            $request->localpart = $localpart;
        }

        if (!isset($request->domain)) {
            $request->domain = strtolower($domain);
        }
    }

    protected function getDomainConfig($request) {
        static $cachedEmail = null;
        static $cachedConfig = null;

        if ($cachedEmail === $request->email) {
            return $cachedConfig;
        }

        $cachedConfig = $this->readConfig($request);
        $cachedEmail = $request->email;

        return $cachedConfig->getDomainConfig($request->domain);
    }

    protected function readConfig($vars) {
        foreach ($vars as $var => $value) {
            $$var = $value;
        }

        $config = new Configuration();
        include './autoconfig.settings.php';
        return $config;
    }

    protected function getUsername($server, $request) {
        if (is_string($server->username)) {
            return $server->username;
        }

        if ($server->username instanceof UsernameResolver) {
            $resolver = $server->username;
            return $resolver->findUsername($request);
        }
    }
}

class MozillaHandler extends RequestHandler {
    public function writeResponse($config, $request) {
        header("Content-Type: text/xml");
        $writer = new XMLWriter();
        $writer->openURI("php://output");

        $this->writeXml($writer, $config, $request);
        $writer->flush();
    }

    protected function parseRequest() {
        return (object)array('email' => $_GET['emailaddress']);
    }

    protected function writeXml($writer, $config, $request) {
        $writer->startDocument("1.0");
        $writer->setIndent(4);
        $writer->startElement("clientConfig");
        $writer->writeAttribute("version", "1.1");

        $this->writeEmailProvider($writer, $config, $request);

        $writer->endElement();
        $writer->endDocument();
    }

    protected function writeEmailProvider($writer, $config, $request) {
        $writer->startElement("emailProvider");
        $writer->writeAttribute("id", $config->id);

        foreach ($config->domains as $domain) {
            $writer->writeElement("domain", $domain);
        }

        $writer->writeElement("displayName", $config->name);
        $writer->writeElement("displayShortName", $config->nameShort);

        foreach ($config->servers as $server) {
            foreach ($server->endpoints as $endpoint) {
                $this->writeServer($writer, $server, $endpoint, $request);
            }
        }

        $writer->endElement();
    }

    protected function writeServer($writer, $server, $endpoint, $request) {
        switch ($server->type) {
            case 'imap':
            case 'pop3':
                $this->writeIncomingServer($writer, $server, $endpoint, $request);
                break;
            case 'smtp':
                $this->writeSmtpServer($writer, $server, $endpoint, $request);
                break;
        }
    }

    protected function writeIncomingServer($writer, $server, $endpoint, $request) {
        $authentication = $this->mapAuthenticationType($endpoint->authentication);
        if (empty($authentication)) return;

        $writer->startElement("incomingServer");
        $writer->writeAttribute("type", $server->type);
        $writer->writeElement("hostname", $server->hostname);
        $writer->writeElement("port", $endpoint->port);
        $writer->writeElement("socketType", $endpoint->socketType);
        $writer->writeElement("username", $this->getUsername($server, $request));
        $writer->writeElement("authentication", $authentication);
        $writer->endElement();
    }

    protected function writeSmtpServer($writer, $server, $endpoint, $request) {
        $authentication = $this->mapAuthenticationType($endpoint->authentication);
        if ($authentication === null) return;

        $writer->startElement("outgoingServer");
        $writer->writeAttribute("type", "smtp");
        $writer->writeElement("hostname", $server->hostname);
        $writer->writeElement("port", $endpoint->port);
        $writer->writeElement("socketType", $endpoint->socketType);

        if ($authentication !== false) {
            $writer->writeElement("username", $this->getUsername($server, $request));
            $writer->writeElement("authentication", $authentication);
        }

        $writer->writeElement("addThisServer", "true");
        $writer->writeElement("useGlobalPreferredServer", "true");
        $writer->endElement();
    }

    protected function mapAuthenticationType($authentication) {
        switch ($authentication) {
            case 'password-cleartext':
                return 'password-cleartext';
            case 'CRAM-MD5':
                return 'password-encrypted';
            case 'none':
                return false;
            default:
                return null;
        }
    }
}

class OutlookHandler extends RequestHandler {
    public $postdata;

    function __construct($data) {
        $this->postdata=$data;
    }

    public function writeResponse($config, $request) {
        header("Content-Type: application/xml");

        $writer = new XMLWriter();
        $writer->openMemory();

        $this->writeXml($writer, $config, $request);

        $response = $writer->outputMemory(true);
        echo $response;
    }

    protected function parseRequest() {
        $postdata = $self->postdata;

        if (strlen($postdata) > 0) {
            $xml = simplexml_load_string($postdata);
            return (object)array('email' => $xml->Request->EMailAddress);
        }

        return null;
    }

    public function writeXml($writer, $config, $request) {
        $writer->startDocument("1.0", "utf-8");
        $writer->setIndent(4);
        $writer->startElement("Autodiscover");
        $writer->writeAttribute("xmlns", "http://schemas.microsoft.com/exchange/autodiscover/responseschema/2006");
        $writer->startElement("Response");
        $writer->writeAttribute("xmlns", "http://schemas.microsoft.com/exchange/autodiscover/outlook/responseschema/2006a");

        $writer->startElement("Account");
        $writer->writeElement("AccountType", "email");
        $writer->writeElement("Action", "settings");

        foreach ($config->servers as $server) {
            foreach ($server->endpoints as $endpoint) {
                if ($this->writeProtocol($writer, $server, $endpoint, $request))
                    break;
            }
        }

        $writer->endElement();

        $writer->endElement();
        $writer->endElement();
        $writer->endDocument();
    }

    protected function writeProtocol($writer, $server, $endpoint, $request) {
        switch ($endpoint->authentication) {
            case 'password-cleartext':
            case 'SPA':
                break;
            case 'none':
                if ($server->type !== 'smtp') return false;
                break;
            default:
                return false;
        }

        $writer->startElement('Protocol');
        $writer->writeElement('Type', strtoupper($server->type));
        $writer->writeElement('Server', $server->hostname);
        $writer->writeElement('Port', $endpoint->port);
        $writer->writeElement('LoginName', $this->getUsername($server, $request));
        $writer->writeElement('DomainRequired', 'off');
        $writer->writeElement('SPA', $endpoint->authentication === 'SPA' ? 'on' : 'off');

        switch ($endpoint->socketType) {
            case 'plain':
                $writer->writeElement("SSL", "off");
                break;
            case 'SSL':
                $writer->writeElement("SSL", "on");
                $writer->writeElement("Encryption", "SSL");
                break;
            case 'STARTTLS':
                $writer->writeElement("SSL", "on");
                $writer->writeElement("Encryption", "TLS");
                break;
        }

        $writer->writeElement("AuthRequired", $endpoint->authentication !== 'none' ? 'on' : 'off');

        if ($server->type == 'smtp') {
            $writer->writeElement('UsePOPAuth', $server->samePassword ? 'on' : 'off');
            $writer->writeElement('SMTPLast', 'off');
        }

        $writer->endElement();

        return true;
    }

    protected function mapAuthenticationType($authentication) {
        switch ($authentication) {
            case 'password-cleartext':
                return 'password-cleartext';
            case 'CRAM-MD5':
                return 'password-encrypted';
            case 'none':
                return false;
            default:
                return null;
        }
    }

}

class ActiveSyncHandler extends RequestHandler {
    public $postdata;

    function __construct($data) {
        $this->postdata=$data;
    }

    public function writeResponse($config, $request) {
        header("Content-Type: application/xml");

        $writer = new XMLWriter();
        $writer->openMemory();

        $this->writeXml($writer, $config, $request);

        $response = $writer->outputMemory(true);
        echo $response;
    }

    protected function parseRequest() {
        $postdata = $this->postdata;

        if (strlen($postdata) > 0) {
            $xml = simplexml_load_string($postdata);
            return (object)array('email' => $xml->Request->EMailAddress);
        }

        return null;
    }

    public function writeXml($writer, $config, $request) {
        $writer->startDocument("1.0", "utf-8");
        $writer->setIndent(4);
        $writer->startElement("Autodiscover");
        $writer->writeAttribute("xmlns", "http://schemas.microsoft.com/exchange/autodiscover/responseschema/2006");
        $writer->writeAttribute("xmlns:xsd", "http://www.w3.org/2001/XMLSchema");
        $writer->writeAttribute("xmlns:xsi", "http://www.w3.org/2001/XMLSchema-instance");

        $writer->startElement("Response");

        $writer->writeElement("Culture",'en:us');

        $writer->startElement("User");
        $writer->writeElement("DisplayName", 'Offcode' /*$config->displayName*/);
        $writer->writeElement("EMailAddress", $request->email);
        $writer->endElement(); // User

        $writer->startElement("Action");
        $writer->startElement("Settings");

        foreach ($config->servers as $server) {
            foreach ($server->endpoints as $endpoint) {
                if ($this->writeProtocol($writer, $server, $endpoint, $request))
                    break;
            }
        }

        $writer->endElement(); //Settings
        $writer->endElement(); //Action
        $writer->endElement(); //Response
        $writer->endElement(); //Autodiscover
        $writer->endDocument();
    }

    protected function writeProtocol($writer, $server, $endpoint, $request) {
        if ($server->type !== 'activesync') return false;

        $writer->startElement('Server');
        $writer->writeElement('Type', 'MobileSync');

        switch ($endpoint->socketType) {
            case 'http':
                $url='http://';
                $suffix=($endpoint->port == 80)?'':(':'.$endpoint->port);
                break;
            case 'https':
                $url='https://';
                $suffix=($endpoint->port == 443)?'':(':'.$endpoint->port);
                break;
        }
        $url.=$server->hostname.$suffix.'/Microsoft-Server-ActiveSync';

        $writer->writeElement('Url', $url);
        $writer->writeElement('Name', $url);

        $writer->endElement();

        return true;
    }

    protected function mapAuthenticationType($authentication) {
        return null;
    }

}


if (strpos($_SERVER['HTTP_HOST'], "autoconfig.") === 0) {
    // Configuration for Mozilla Thunderbird, Evolution, KMail, Kontact
    $handler = new MozillaHandler();
} else if (strpos($_SERVER['HTTP_HOST'], "autodiscover.") === 0) {
    $body=file_get_contents("php://input");
    file_put_contents('indata',$body);
    if (strlen($body) > 0) {
        $xml = simplexml_load_string($body);
        if ($xml->Request->AcceptableResponseSchema=="http://schemas.microsoft.com/exchange/autodiscover/mobilesync/responseschema/2006") {
            // Configuration for Windows 10 Mail
            $handler = new ActiveSyncHandler($body);
        } else {
            // Configuration for Outlook
            $handler = new OutlookHandler($body);
        }
    }
}

$handler->handleRequest();