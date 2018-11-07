<?php
// This configuration file is really just a plain PHP source file.
// If needed, custom logic can be created.
//
// The following variables are available throughout the file:
// $email       Full email address the settings are requested for
// $domain      The domain part of the email address 
// $localpart   The part before the @ sign of the email address

// Add a new configuration set for a new group of domains. The argument
// is an ID used in the Thunderbird autoconfiguration and can be anything.
$cfg = $config->add('example.com');

// Name and short name for the offered service, as used by Mozilla Thunderbird
$cfg->name = 'Example mail services';
$cfg->nameShort = 'Example';

// Domains for which these settings apply, in lowercase.
$cfg->domains = [ 'example.com', 'example.net', 'example.org' ];

// This is the username associated with the email address. If some kind of lookup
// needs to occur to map the email address to a username, this can be done by
// implementing the UsernameResolver interface, or by just embedding the code here.
// One such UsernameResolvers is currently built in, to aliases files used on many Linux systems.
//
// Some examples:
//   "$localpart";                                          Use the localpart of the email address as username
//   new AliasesFileUsernameResolver();                     Scan /etc/mail/aliases to obtain the username
//   new AliasesFileUsernameResolver("/etc/mail/$domain");  Same but with separate file per domain
$cfg->username = "$localpart";

// Add available servers here.
// addServer($type, $hostname)
// $type             Server type. Possible types are: imap, pop3, smtp
// $hostname         The host name or IP address of the server
//
// Each server should have one or more endpoints, defined by chaining one or more calls to withEndpoint:
// withEndpoint($socketType, $port, $authenticatonType)
// $socketType       Required and can be one of: plain, STARTTLS, SSL
// $port             The port number on which the server will listen. Omit to use defaults.
// $authentication   The authentication scheme to use:
//                   password-cleartext     Default. Should be used only with STARTTLS or SSL
//                   CRAM-MD5               Not supported by Outlook  
//                   SPA                    Not supported by Thunderbird
//                   none                   Only valid for SMTP. Not recommended however.

// Example IMAP server for incoming mail, running on port 143 (TLS) and 993 (SSL)
$cfg->addServer('imap', 'imap.example.com')
    ->withEndpoint('STARTTLS')
    ->withEndpoint('SSL');

// Example POP3 server for incoming mail, running on port 110 (TLS) and 995 (SSL)    
$cfg->addServer('pop3', 'pop.example.com')
    ->withEndpoint('STARTTLS')
    ->withEndpoint('SSL');    

// Example SMTP server for outgoing mail, running on port 587 (TLS) and 465 (SSL)
$cfg->addServer('smtp', 'smtp.example.com')
    ->withEndpoint('STARTTLS', 587)
    ->withEndpoint('SSL');

// Example ActiveSync server
$cfg->addServer('activesync', 'mail.example.com')
    ->withEndpoint('https', 443);
