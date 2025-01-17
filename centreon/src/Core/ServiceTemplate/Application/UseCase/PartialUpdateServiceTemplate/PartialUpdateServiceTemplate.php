<?php

/*
 * Copyright 2005 - 2023 Centreon (https://www.centreon.com/)
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * https://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 *
 * For more information : contact@centreon.com
 *
 */

declare(strict_types=1);

namespace Core\ServiceTemplate\Application\UseCase\PartialUpdateServiceTemplate;

use Assert\AssertionFailedException;
use Centreon\Domain\Contact\Contact;
use Centreon\Domain\Contact\Interfaces\ContactInterface;
use Centreon\Domain\Log\LoggerTrait;
use Centreon\Domain\Repository\Interfaces\DataStorageEngineInterface;
use Core\Application\Common\UseCase\ConflictResponse;
use Core\Application\Common\UseCase\ErrorResponse;
use Core\Application\Common\UseCase\ForbiddenResponse;
use Core\Application\Common\UseCase\NoContentResponse;
use Core\Application\Common\UseCase\NotFoundResponse;
use Core\Application\Common\UseCase\PresenterInterface;
use Core\CommandMacro\Application\Repository\ReadCommandMacroRepositoryInterface;
use Core\CommandMacro\Domain\Model\CommandMacro;
use Core\CommandMacro\Domain\Model\CommandMacroType;
use Core\Common\Application\Type\NoValue;
use Core\HostTemplate\Application\Repository\ReadHostTemplateRepositoryInterface;
use Core\Macro\Application\Repository\ReadServiceMacroRepositoryInterface;
use Core\Macro\Application\Repository\WriteServiceMacroRepositoryInterface;
use Core\Macro\Domain\Model\Macro;
use Core\Macro\Domain\Model\MacroDifference;
use Core\Macro\Domain\Model\MacroManager;
use Core\Security\AccessGroup\Application\Repository\ReadAccessGroupRepositoryInterface;
use Core\Security\AccessGroup\Domain\Model\AccessGroup;
use Core\ServiceCategory\Application\Repository\ReadServiceCategoryRepositoryInterface;
use Core\ServiceCategory\Application\Repository\WriteServiceCategoryRepositoryInterface;
use Core\ServiceCategory\Domain\Model\ServiceCategory;
use Core\ServiceTemplate\Application\Exception\ServiceTemplateException;
use Core\ServiceTemplate\Application\Repository\ReadServiceTemplateRepositoryInterface;
use Core\ServiceTemplate\Application\Repository\WriteServiceTemplateRepositoryInterface;
use Core\ServiceTemplate\Domain\Model\ServiceTemplate;
use Core\ServiceTemplate\Domain\Model\ServiceTemplateInheritance;
use Utility\Difference\BasicDifference;

class PartialUpdateServiceTemplate
{
    use LoggerTrait;

    /** @var AccessGroup[] */
    private array $accessGroups;

    public function __construct(
        private readonly ReadServiceTemplateRepositoryInterface $readRepository,
        private readonly WriteServiceTemplateRepositoryInterface $writeRepository,
        private readonly ReadHostTemplateRepositoryInterface $readHostTemplateRepository,
        private readonly ReadServiceCategoryRepositoryInterface $readServiceCategoryRepository,
        private readonly WriteServiceCategoryRepositoryInterface $writeServiceCategoryRepository,
        private readonly ReadAccessGroupRepositoryInterface $readAccessGroupRepository,
        private readonly ReadServiceTemplateRepositoryInterface $readServiceTemplateRepository,
        private readonly ReadServiceMacroRepositoryInterface $readServiceMacroRepository,
        private readonly WriteServiceMacroRepositoryInterface $writeServiceMacroRepository,
        private readonly ReadCommandMacroRepositoryInterface $readCommandMacroRepository,
        private readonly ContactInterface $user,
        private readonly DataStorageEngineInterface $storageEngine,
    ) {
    }

    public function __invoke(
        PartialUpdateServiceTemplateRequest $request,
        PresenterInterface $presenter
    ): void {
        try {
            $this->info('Update the service template', ['request' => $request]);
            if (! $this->user->hasTopologyRole(Contact::ROLE_CONFIGURATION_SERVICES_TEMPLATES_READ_WRITE)) {
                $this->error(
                    "User doesn't have sufficient rights to update a service template",
                    ['user_id' => $this->user->getId()]
                );
                $presenter->setResponseStatus(
                    new ForbiddenResponse(ServiceTemplateException::updateNotAllowed())
                );

                return;
            }

            $serviceTemplate = $this->readRepository->findById($request->id);
            if ($serviceTemplate === null) {
                $this->error('Service template not found', ['service_template_id' => $request->id]);
                $presenter->setResponseStatus(new NotFoundResponse('Service template'));

                return;
            }

            if (! $this->user->isAdmin()) {
                $this->accessGroups = $this->readAccessGroupRepository->findByContact($this->user);
            }

            $this->assertAllProperties($request);
            $this->updatePropertiesInTransaction($request, $serviceTemplate);

            $presenter->setResponseStatus(new NoContentResponse());
        } catch (ServiceTemplateException $ex) {
            $presenter->setResponseStatus(
                match ($ex->getCode()) {
                    ServiceTemplateException::CODE_CONFLICT => new ConflictResponse($ex),
                    default => new ErrorResponse($ex),
                }
            );
            $this->error($ex->getMessage(), ['trace' => $ex->getTraceAsString()]);
        } catch (\Throwable $ex) {
            $presenter->setResponseStatus(new ErrorResponse(ServiceTemplateException::errorWhileUpdating()));
            $this->error($ex->getMessage(), ['trace' => $ex->getTraceAsString()]);
        }
    }

    /**
     * Check if all host template ids exist.
     *
     * @param list<int> $hostTemplatesIds
     *
     * @throws ServiceTemplateException
     */
    private function assertHostTemplateIds(array $hostTemplatesIds): void
    {
        $hostTemplateIds = array_unique($hostTemplatesIds);
        $hostTemplateIdsFound = $this->readHostTemplateRepository->findAllExistingIds($hostTemplateIds);
        if ([] !== ($idsNotFound = array_diff($hostTemplateIds, $hostTemplateIdsFound))) {
            throw ServiceTemplateException::idsDoesNotExist('host_templates', $idsNotFound);
        }
    }

    /**
     * @param list<int> $serviceCategoriesIds
     *
     * @throws ServiceTemplateException
     * @throws \Throwable
     */
    private function assertServiceCategories(array $serviceCategoriesIds): void
    {
        if ($this->user->isAdmin()) {
            $serviceCategoriesIdsFound = $this->readServiceCategoryRepository->findAllExistingIds(
                $serviceCategoriesIds
            );
        } else {
            $serviceCategoriesIdsFound = $this->readServiceCategoryRepository->findAllExistingIdsByAccessGroups(
                $serviceCategoriesIds,
                $this->accessGroups
            );
        }

        if ([] !== ($idsNotFound = array_diff($serviceCategoriesIds, $serviceCategoriesIdsFound))) {
            throw ServiceTemplateException::idsDoesNotExist('service_categories', $idsNotFound);
        }
    }

    /**
     * @param PartialUpdateServiceTemplateRequest $request
     *
     * @throws \Throwable
     */
    private function linkServiceTemplateToHostTemplates(PartialUpdateServiceTemplateRequest $request): void
    {
        if (! is_array($request->hostTemplates)) {
            return;
        }

        $this->info('Unlink existing host templates from service template', [
            'service_template_id' => $request->id,
            'host_templates' => $request->hostTemplates,
        ]);
        $this->writeRepository->unlinkHosts($request->id);
        $this->info('Link host templates to service template', [
            'service_template_id' => $request->id,
            'host_templates' => $request->hostTemplates,
        ]);
        $this->writeRepository->linkToHosts($request->id, $request->hostTemplates);
    }

    /**
     * @param PartialUpdateServiceTemplateRequest $request
     *
     * @throws \Throwable
     */
    private function linkServiceTemplateToServiceCategories(PartialUpdateServiceTemplateRequest $request): void
    {
        if (! is_array($request->serviceCategories)) {
            return;
        }

        if ($this->user->isAdmin()) {
            $originalServiceCategories = $this->readServiceCategoryRepository->findByService($request->id);
        } else {
            $originalServiceCategories = $this->readServiceCategoryRepository->findByServiceAndAccessGroups(
                $request->id,
                $this->accessGroups
            );
        }
        $this->info('Original service categories found', ['service_categories' => $originalServiceCategories]);

        $originalServiceCategoriesIds = array_map(
            static fn(ServiceCategory $serviceCategory): int => $serviceCategory->getId(),
            $originalServiceCategories
        );

        $serviceCategoryDifferences = new BasicDifference(
            $originalServiceCategoriesIds,
            array_unique($request->serviceCategories)
        );

        $serviceCategoriesToAdd = $serviceCategoryDifferences->getAdded();
        $serviceCategoriesToRemove = $serviceCategoryDifferences->getRemoved();

        $this->info(
            'Unlink existing service categories from service',
            ['service_categories' => $serviceCategoriesToRemove]
        );
        $this->writeServiceCategoryRepository->unlinkFromService($request->id, $serviceCategoriesToRemove);

        $this->info(
            'Link existing service categories to service',
            ['service_categories' => $serviceCategoriesToAdd]
        );
        $this->writeServiceCategoryRepository->linkToService($request->id, $serviceCategoriesToAdd);
    }

    /**
     * @param PartialUpdateServiceTemplateRequest $request
     * @param ServiceTemplate $serviceTemplate
     *
     * @throws ServiceTemplateException
     * @throws \Throwable
     */
    private function updatePropertiesInTransaction(
        PartialUpdateServiceTemplateRequest $request,
        ServiceTemplate $serviceTemplate
    ): void {
        $this->debug('Start transaction');
        $this->storageEngine->startTransaction();
        try {
            $this->linkServiceTemplateToHostTemplates($request);
            $this->linkServiceTemplateToServiceCategories($request);
            $this->updateMacros($request, $serviceTemplate);

            $this->debug('Commit transaction');
            $this->storageEngine->commitTransaction();
        } catch (\Throwable $ex) {
            $this->debug('Rollback transaction');
            $this->storageEngine->rollbackTransaction();

            throw $ex;
        }
    }

    /**
     * Assertions are not required to update macros.
     *
     * @param PartialUpdateServiceTemplateRequest $request
     *
     * @throws ServiceTemplateException
     * @throws \Throwable
     */
    private function assertAllProperties(PartialUpdateServiceTemplateRequest $request): void
    {
        if (! ($request->hostTemplates instanceof NoValue)) {
            $this->assertHostTemplateIds($request->hostTemplates);
        }
        if (! ($request->serviceCategories instanceof NoValue)) {
            $this->assertServiceCategories($request->serviceCategories);
        }
    }

    /**
     * @param PartialUpdateServiceTemplateRequest $request
     * @param ServiceTemplate $serviceTemplate
     *
     * @throws AssertionFailedException
     * @throws \Throwable
     */
    private function updateMacros(PartialUpdateServiceTemplateRequest $request, ServiceTemplate $serviceTemplate): void
    {
        if (! is_array($request->macros)) {
            return;
        }

        $this->info('Add macros', ['service_template_id' => $request->id]);

        /**
         * @var array<string,Macro> $inheritedMacros
         * @var array<string,CommandMacro> $commandMacros
         */
        [$directMacros, $inheritedMacros, $commandMacros] = $this->findAllMacros(
            $request->id,
            $serviceTemplate->getCommandId()
        );

        $macros = [];
        foreach ($request->macros as $macro) {
            $macro = MacroFactory::create($macro, $request->id, $directMacros, $inheritedMacros);
            $macros[$macro->getName()] = $macro;
        }

        $macrosDiff = new MacroDifference();
        $macrosDiff->compute($directMacros, $inheritedMacros, $commandMacros, $macros);

        MacroManager::setOrder($macrosDiff, $macros, []);

        foreach ($macrosDiff->removedMacros as $macro) {
            $this->info('Delete the macro ' . $macro->getName());
            $this->writeServiceMacroRepository->delete($macro);
        }

        foreach ($macrosDiff->updatedMacros as $macro) {
            $this->info('Update the macro ' . $macro->getName());
            $this->writeServiceMacroRepository->update($macro);
        }

        foreach ($macrosDiff->addedMacros as $macro) {
            if ($macro->getDescription() === '') {
                $macro->setDescription(
                    isset($commandMacros[$macro->getName()])
                    ? $commandMacros[$macro->getName()]->getDescription()
                    : ''
                );
            }
            $this->info('Add the macro ' . $macro->getName());
            $this->writeServiceMacroRepository->add($macro);
        }
    }

    /**
     * @param int $serviceTemplateId
     * @param int|null $checkCommandId
     *
     * @throws \Throwable
     *
     * @return array{
     *      array<string,Macro>,
     *      array<string,Macro>,
     *      array<string,CommandMacro>
     * }
     */
    private function findAllMacros(int $serviceTemplateId, ?int $checkCommandId): array
    {
        $serviceTemplateInheritances = $this->readServiceTemplateRepository->findParents($serviceTemplateId);
        $inheritanceLine = ServiceTemplateInheritance::createInheritanceLine(
            $serviceTemplateId,
            $serviceTemplateInheritances
        );
        $existingMacros = $this->readServiceMacroRepository->findByServiceIds($serviceTemplateId, ...$inheritanceLine);

        [$directMacros, $inheritedMacros] = Macro::resolveInheritance(
            $existingMacros,
            $inheritanceLine,
            $serviceTemplateId
        );

        /** @var array<string,CommandMacro> $commandMacros */
        $commandMacros = [];
        if ($checkCommandId !== null) {
            $existingCommandMacros = $this->readCommandMacroRepository->findByCommandIdAndType(
                $checkCommandId,
                CommandMacroType::Service
            );

            $commandMacros = MacroManager::resolveInheritanceForCommandMacro($existingCommandMacros);
        }

        return [$directMacros, $inheritedMacros, $commandMacros];
    }
}
