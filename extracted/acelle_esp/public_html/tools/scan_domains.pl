#!/usr/bin/perl -w
# perl -MCPAN -e "CPAN::Shell->notest('install', 'CloudFlare::Client')"
my $APP_CLOUDFLARE_EMAIL="info\@linacrm.com";
my $APP_CLOUDFLARE_APIKEY="af7a6438664049d7cafd7b8f3650726b8fb20";



my @rbl=(
        'dbl.spamhaus.org',
        'b.barracudacentral.org',
        'cbl.abuseat.org', 
        'http.dnsbl.sorbs.net',
        'misc.dnsbl.sorbs.net',
        'socks.dnsbl.sorbs.net',
        'web.dnsbl.sorbs.net',
        'dnsbl-1.uceprotect.net',
        'dnsbl-3.uceprotect.net',
        'sbl.spamhaus.org',
        'zen.spamhaus.org');


use DBI;
my %env;
my $dbh;
$env{DB_DATABASE} = "trackingas";
$env{DB_HOST} = "62.210.13.211";
$env{DB_USERNAME} = "ses_remote";
$env{DB_PASSWORD} = "bGh9CaF897q";
sub dbconn {
$dbh = DBI->connect("DBI:mysql:".$env{DB_DATABASE}.":".$env{DB_HOST}, $env{DB_USERNAME}, $env{DB_PASSWORD} ,{ PrintError => 1, RaiseError => 0 }) or die "Unable to connect to MySQL\n";
}

use Mojo::UserAgent;
my $ua  = Mojo::UserAgent->new;
Mojo::IOLoop->start unless Mojo::IOLoop->is_running;
use Data::Dumper;
use MojoX::CloudFlare::Simple;
my $cloudflare = MojoX::CloudFlare::Simple->new(
    email => $APP_CLOUDFLARE_EMAIL,
    key   => $APP_CLOUDFLARE_APIKEY,
);

sub check_domain {
my ($domain) = @_;
foreach(@rbl) {
my $out = qx/dig +short -t a $domain.$_./;
print "Domain: $domain respond: $out from $_\n";
if ($out =~ /127/) {
print "Domain $domain is in $_ DBL blacklist\n";
addbl($domain,$_);
}
}
}


sub check_domains {
my (@domains) = @_;
print "Checking domains...\n";
for $dom (@domains) {
check_domain($dom);
}
}

sub addbl {
my ($domain,$dbl) = @_;
my $sta = $dbh->prepare("INSERT IGNORE INTO dnsbl_rbl (name,dnsbl) VALUES(?,?)");
$sta->execute($domain,$dbl);
}

sub list_domains {
my @domains = ();
print "Generating domain list...\n";
for my $i (0..9) {
my $json = $ua->get('https://api.cloudflare.com/client/v4/zones?name=&status=active&page='.$i.'&per_page=50&order=name&direction=desc&match=any' => {Accept => '*/*', 'X-Auth-Email' => $APP_CLOUDFLARE_EMAIL, 'X-Auth-Key' => $APP_CLOUDFLARE_APIKEY})->result->json;
last if ($json->{success} != 1);
for (@{$json->{result}}) {
push @domains, $_->{name};
}
}
return @domains;
}


dbconn();
@domains = list_domains();
check_domains(@domains);
