<?php
/**
 * sysPass
 *
 * @author    nuxsmin
 * @link      http://syspass.org
 * @copyright 2012-2018, Rubén Domínguez nuxsmin@$syspass.org
 *
 * This file is part of sysPass.
 *
 * sysPass is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * sysPass is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 *  along with sysPass.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace SP\Services\Crypt;

use SP\Core\Crypt\Crypt;
use SP\Core\Crypt\Hash;
use SP\Core\Events\Event;
use SP\Core\Exceptions\SPException;
use SP\Services\Config\ConfigService;
use SP\Services\Service;
use SP\Services\ServiceException;
use SP\Util\Util;

/**
 * Class TemporaryMasterPassService
 *
 * @package SP\Services\Crypt
 */
class TemporaryMasterPassService extends Service
{
    /**
     * Número máximo de intentos
     */
    const MAX_ATTEMPTS = 50;
    /**
     * @var ConfigService
     */
    protected $configService;

    /**
     * Devuelve la clave maestra que ha sido encriptada con la clave temporal
     *
     * @param $randomKey string con la clave utilizada para encriptar
     * @return string con la clave maestra desencriptada
     * @throws \Defuse\Crypto\Exception\CryptoException
     * @throws \Defuse\Crypto\Exception\EnvironmentIsBrokenException
     * @throws \Defuse\Crypto\Exception\BadFormatException
     */
    public static function getTempMasterPass($randomKey)
    {
        $securedKey = Crypt::unlockSecuredKey(ConfigDB::getValue('tempmaster_passkey'), $randomKey);

        return Crypt::decrypt(ConfigDB::getValue('tempmaster_pass'), $securedKey, $randomKey);
    }

    /**
     * Crea una clave temporal para encriptar la clave maestra y guardarla.
     *
     * @param int $maxTime El tiempo máximo de validez de la clave
     * @return string
     * @throws ServiceException
     */
    public function create($maxTime = 14400)
    {
        try {
            // Encriptar la clave maestra con hash aleatorio generado
            $randomKey = Util::generateRandomBytes(32);
            $securedKey = Crypt::makeSecuredKey($randomKey);

            $this->configService->save('tempmaster_passkey', $securedKey);
            $this->configService->save('tempmaster_passhash', Hash::hashKey($randomKey));
            $this->configService->save('tempmaster_passtime', time());
            $this->configService->save('tempmaster_maxtime', time() + $maxTime);
            $this->configService->save('tempmaster_attempts', 0);

            // Guardar la clave temporal hasta que finalice la sesión
            $this->session->setTemporaryMasterPass($randomKey);

            $this->eventDispatcher->notifyEvent('create.tempMasterPass', new Event($this, [__u('Generar Clave Temporal')]));

            return $randomKey;
        } catch (\Exception $e) {
            processException($e);

            throw new ServiceException(__u('Error al generar clave temporal'), SPException::ERROR);
        }
    }

    /**
     * Comprueba si la clave temporal es válida
     *
     * @param string $pass clave a comprobar
     * @return bool
     * @throws ServiceException
     */
    public function checkTempMasterPass($pass)
    {
        try {
            $isValid = false;
            $passTime = (int)$this->configService->getByParam('tempmaster_passtime');
            $passMaxTime = (int)$this->configService->getByParam('tempmaster_maxtime');
            $attempts = (int)$this->configService->getByParam('tempmaster_attempts');

            // Comprobar si el tiempo de validez o los intentos se han superado
            if ($passMaxTime === 0) {
                $this->eventDispatcher->notifyEvent('check.tempMasterPass', new Event($this, [__u('Clave temporal caducada')]));

                return $isValid;
            }

            if ((!empty($passTime) && time() > $passMaxTime)
                || $attempts >= self::MAX_ATTEMPTS
            ) {
                $this->configService->save('tempmaster_passkey', '');
                $this->configService->save('tempmaster_passhash', '');
                $this->configService->save('tempmaster_maxtime', '');
                $this->configService->save('tempmaster_attempts', 0);

                $this->eventDispatcher->notifyEvent('check.tempMasterPass', new Event($this, [__u('Clave temporal caducada')]));

                return $isValid;
            }

            $isValid = Hash::checkHashKey($pass, $this->configService->getByParam('tempmaster_passhash'));

            if (!$isValid) {
                $this->configService->save('tempmaster_attempts', $attempts + 1);
            }

            return $isValid;
        } catch (\Exception $e) {
            processException($e);

            throw new ServiceException(__('Error al comprobar clave temporal'), SPException::ERROR);
        }
    }

    /**
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     */
    protected function initialize()
    {
        $this->configService = $this->dic->get(ConfigService::class);
    }
}