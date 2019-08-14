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

package centreon::gorgone::api;

use strict;
use warnings;
use centreon::gorgone::common;
use ZMQ::LibZMQ4;
use ZMQ::Constants qw(:all);

my $socket;
my $result;

sub root {
    my (%options) = @_;

    $options{logger}->writeLogInfo("[api] Requesting '" . $options{uri} . "' [" . $options{method} . "]");

    my %dispatch;
    foreach my $action (keys $options{modules_events}) {
        next if (!defined($options{modules_events}->{$action}->{api}->{uri}));
        $dispatch{$options{modules_events}->{$action}->{api}->{method} . '_' .
            $options{modules_events}->{$action}->{api}->{uri}} = $action;
    }

    my $response;
    if ($options{method} eq 'GET' && $options{uri} =~ /^\/api\/get\/(.*)$/) {
        $response = get_log(socket => $options{socket}, token => $1);
    } elsif ($options{method} eq 'GET'
        && $options{uri} =~ /^\/api\/(target\/(\w*)\/)?(\w+)\/?(\w*?)$/
        && defined($dispatch{'GET_/' . $3})) {
        $response = call_action(
            socket => $options{socket},
            action => $dispatch{'GET_/' . $3},
            target => $2,
            data => { params => $options{params} }
        );
    } elsif ($options{method} eq 'POST'
        && $options{uri} =~ /^\/api\/(target\/(\w*)\/)?(\w+)\/?(\w*?)$/
        && defined($dispatch{'POST_/' . $3})) {
        $response = call_action(
            socket => $options{socket},
            action => $dispatch{'POST_/' . $3},
            target => $2,
            data => { content => $options{content}, params => $options{params} }
        );
    } elsif ($options{method} eq 'DELETE'
        && $options{uri} =~ /^\/api\/(target\/(\w*)\/)?(\w+)\/?(\w*?)$/
        && defined($dispatch{'DELETE_/' . $3})) {
        $response = call_action(
            socket => $options{socket},
            action => $dispatch{'DELETE_/' . $3},
            target => $2,
            data => { params => $options{params} }
        );
    } else {
        $response = '{"error":"method_unknown","message":"Method not implemented"}';
    }

    return $response;
}

sub call_action {
    my (%options) = @_;
    
    centreon::gorgone::common::zmq_send_message(
        socket => $options{socket},
        action => $options{action},
        target => $options{target},
        data => $options{data},
        json_encode => 1
    );

    $socket = $options{socket};    
    my $poll = [
        {
            socket  => $options{socket},
            events  => ZMQ_POLLIN,
            callback => \&event,
        }
    ];

    my $rev = zmq_poll($poll, 5000);

    my $response = "";
    if (defined($result->{token}) && $result->{token} ne '') {
        $response = '{"token":"' . $result->{token} . '"}';
    } else {
        $response = '{"error":"no_token","message":"Cannot retrieve token from ack"}';
    }

    return $response;
}

sub get_log {
    my (%options) = @_;
    
    centreon::gorgone::common::zmq_send_message(
        socket => $options{socket},
        action => 'GETLOG',
        data => {
            token => $options{token}
        },
        json_encode => 1
    );    

    $socket = $options{socket};
    my $poll = [
        {
            socket  => $options{socket},
            events  => ZMQ_POLLIN,
            callback => \&event,
        }
    ];

    my $rev = zmq_poll($poll, 5000);

    return $result->{data};
}

sub event {
    while (1) {
        my $message = centreon::gorgone::common::zmq_dealer_read_message(socket => $socket);
        
        $result = {};
        if ($message =~ /^\[(.*?)\]\s+\[(.*?)\]\s+\[.*?\]\s+(.*)$/m || 
            $message =~ /^\[(.*?)\]\s+\[(.*?)\]\s+(.*)$/m) {
            $result = {
                action => $1,
                token => $2,
                data => $3,
            };
        }
        
        last unless (centreon::gorgone::common::zmq_still_read(socket => $socket));
    }
}

1;
