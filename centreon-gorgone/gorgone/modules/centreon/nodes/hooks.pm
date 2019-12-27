# 
# Copyright 2019 Centreon (http://www.centreon.com/)
#
# Centreon is a full-fledged industry-strength solution that meets
# the needs in IT infrastructure and application monitoring for
# service performance.
#
# Licensed under the Apache License, Version 2.0 (the "License");
# you may not use this file except in compliance with the License.
# You may obtain a copy of the License at
#
#     http://www.apache.org/licenses/LICENSE-2.0
#
# Unless required by applicable law or agreed to in writing, software
# distributed under the License is distributed on an "AS IS" BASIS,
# WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
# See the License for the specific language governing permissions and
# limitations under the License.
#

package gorgone::modules::centreon::nodes::hooks;

use warnings;
use strict;
use JSON::XS;
use gorgone::class::core;
use gorgone::modules::centreon::nodes::class;

use constant NAMESPACE => 'centreon';
use constant NAME => 'nodes';
use constant EVENTS => [
    { event => 'CENTREONNODESREADY' },
];

my $config_core;
my $config;
my ($config_db_centreon);
my $nodes = {};
my $stop = 0;

sub register {
    my (%options) = @_;
    
    $config = $options{config};
    $config_core = $options{config_core};
    $config_db_centreon = $options{config_db_centreon};
    $config->{resync_time} = defined($config->{resync_time}) && $config->{resync_time} =~ /(\d+)/ ? $1 : 600;
    return (1, NAMESPACE, NAME, EVENTS);
}

sub init {
    my (%options) = @_;

    create_child(logger => $options{logger});
}

sub routing {
    my (%options) = @_;

    my $data;
    eval {
        $data = JSON::XS->new->utf8->decode($options{data});
    };
    if ($@) {
        $options{logger}->writeLogError("[nodes] Cannot decode json data: $@");
        gorgone::standard::library::add_history(
            dbh => $options{dbh},
            code => 10, token => $options{token},
            data => { message => 'gorgonenodes: cannot decode json' },
            json_encode => 1
        );
        return undef;
    }
    
    if ($options{action} eq 'CENTREONNODESREADY') {
        $nodes->{ready} = 1;
        return undef;
    }
    
    if (gorgone::class::core::waiting_ready(ready => \$nodes->{ready}) == 0) {
        gorgone::standard::library::add_history(
            dbh => $options{dbh},
            code => 10, token => $options{token},
            data => { message => 'gorgonenodes: still no ready' },
            json_encode => 1
        );
        return undef;
    }
    
    gorgone::standard::library::zmq_send_message(
        socket => $options{socket},
        identity => 'gorgonenodes',
        action => $options{action},
        data => $options{data},
        token => $options{token},
    );
}

sub gently {
    my (%options) = @_;

    $stop = 1;
    $options{logger}->writeLogDebug("[nodes] Send TERM signal");
    if ($nodes->{running} == 1) {
        CORE::kill('TERM', $nodes->{pid});
    }
}

sub kill {
    my (%options) = @_;

    if ($nodes->{running} == 1) {
        $options{logger}->writeLogDebug("[nodes] Send KILL signal for pool");
        CORE::kill('KILL', $nodes->{pid});
    }
}

sub kill_internal {
    my (%options) = @_;

}

sub check {
    my (%options) = @_;

    my $count = 0;
    foreach my $pid (keys %{$options{dead_childs}}) {
        # Not me
        next if ($nodes->{pid} != $pid);
        
        $nodes = {};
        delete $options{dead_childs}->{$pid};
        if ($stop == 0) {
            create_child(logger => $options{logger});
        }
    }
    
    $count++ if (defined($nodes->{running}) && $nodes->{running} == 1);
    
    return $count;
}

sub broadcast {
    my (%options) = @_;

    routing(%options);
}

# Specific functions
sub create_child {
    my (%options) = @_;
    
    $options{logger}->writeLogInfo("[nodes] Create module 'nodes' process");
    my $child_pid = fork();
    if ($child_pid == 0) {
        $0 = 'gorgone-nodes';
        my $module = gorgone::modules::centreon::nodes::class->new(
            logger => $options{logger},
            config_core => $config_core,
            config => $config,
            config_db_centreon => $config_db_centreon,
        );
        $module->run();
        exit(0);
    }
    $options{logger}->writeLogDebug("[nodes] PID $child_pid (gorgone-nodes)");
    $nodes = { pid => $child_pid, ready => 0, running => 1 };
}

1;
