#!/usr/bin/perl -w

use Redis;
use JSON::XS;# qw(decode_json encode_json);
use Data::Dumper;
my $redis = Redis->new(server => '127.0.0.1:6379', reconnect => 60);

# jeigu egzistuoja keywordas
if ($redis->exists("5ae47921a76dc")) {
my $val = $redis->get("5ae47921a76dc");
# paimam
my $json = decode_json($val);
#print $json->{first_name}."\n";
# pakeiciam varda
#$json->{first_name} = "Jonas";
#$encoded = encode_json($json);
# setinam atgal
#$redis->set('5ad36f6b44252',$encoded);
# printinam resultus
print Dumper($json);
print "From email: $json->{from_email}";

} else {
print "nera \n";
}

if (!$redis->hexists("blacklists_fast","test\@test.lt")) {
print "Blackliste manes nera\n";
}
