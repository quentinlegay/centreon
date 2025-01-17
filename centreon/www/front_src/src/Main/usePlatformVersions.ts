import { useCallback } from 'react';

import { useAtom } from 'jotai';
import { isNil, keys } from 'ramda';

import { getData, useRequest } from '@centreon/ui';

import { platformVersionsEndpoint } from '../api/endpoint';
import { PlatformVersions } from '../api/models';
import { platformVersionsDecoder } from '../api/decoders';

import { platformVersionsAtom } from './atoms/platformVersionsAtom';

interface UsePlatformVersionsState {
  getModules: () => Array<string> | null;
  getPlatformVersions: () => void;
  getWidgets: () => Array<string> | null;
}

const usePlatformVersions = (): UsePlatformVersionsState => {
  const { sendRequest: sendPlatformVersions } = useRequest<PlatformVersions>({
    decoder: platformVersionsDecoder,
    request: getData
  });

  const [platformVersions, setPlatformVersions] = useAtom(platformVersionsAtom);

  const getPlatformVersions = useCallback((): void => {
    sendPlatformVersions({ endpoint: platformVersionsEndpoint }).then(
      setPlatformVersions
    );
  }, []);

  const getModules = useCallback((): Array<string> | null => {
    if (isNil(platformVersions)) {
      return null;
    }

    return keys(platformVersions?.modules) as Array<string>;
  }, [platformVersions]);

  const getWidgets = useCallback((): Array<string> | null => {
    if (isNil(platformVersions)) {
      return null;
    }

    return keys(platformVersions?.widgets) as Array<string>;
  }, [platformVersions]);

  return {
    getModules,
    getPlatformVersions,
    getWidgets
  };
};

export default usePlatformVersions;
