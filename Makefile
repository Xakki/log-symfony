SHELL = /bin/bash
### https://makefiletutorial.com/

include .env
export

.DEFAULT_GOAL := help

HOST_NAME := $(shell hostname)
HOST_IP  := $(shell hostname -I 2>/dev/null | awk '{print $$1}' || echo unknown)
TTY ?= $(shell if [ -t 0 ]; then echo "-it"; else echo "-i"; fi)

DOCKER_CPUS ?= 2
DOCKER_MEMORY ?= 1g
UID := $(shell id -u)
GID := $(shell id -g)

# --user: container must not stamp vendor/ and composer.lock as root on the host mount.
# COMPOSER_HOME: a non-root uid has no writable $HOME inside the image.
docker := docker run --rm ${TTY} --cpus=${DOCKER_CPUS} --memory=${DOCKER_MEMORY} \
	--user $(UID):$(GID) -e COMPOSER_HOME=/tmp/composer -e HOME=/tmp \
	-v $(PWD):/app ${DOCKER_USER}/${TAG}
composer := $(docker) composer

help: ## Show this help
	@grep -hE '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-16s\033[0m %s\n", $$1, $$2}'

run: docker-build composer-u test ## Build img, update deps, run full test suite

docker-login: ## Login to docker registry
	docker login ${HOST} -u ${DOCKER_USER} -p ${DOCKER_PASS}

docker-push: ## Push built image to registry
	docker push ${DOCKER_USER}/${TAG}

docker-build: ## Pull base img and build project img
	docker pull ${PHP_IMAGE}
	docker build -t ${DOCKER_USER}/${TAG} --build-arg PHP_IMAGE=${PHP_IMAGE} .

bash: ## Open shell in container
	$(docker) bash

composer-i: ## composer install
	$(composer) i

composer-u: ## composer update (name=pkg to target one)
	$(composer) u $(name)

cs-fix: ## Auto-fix code style (phpcbf)
	$(composer) cs-fix

cs-check: ## Check code style (phpcs)
	$(composer) cs-check

phpstan: ## Run static analysis
	$(composer) phpstan

phpunit: ## Run unit tests
	$(composer) phpunit

phpunit-filter: ## Run one test (name=<TestMethodOrClass>)
	$(docker) vendor/bin/phpunit -c phpunit.xml --no-coverage --filter $(name)

fix-perms: ## Chown repo back to host user (after a root-owned run)
	docker run --rm -v $(PWD):/app -w /app ${PHP_IMAGE} chown -R $(UID):$(GID) .

test: ## Run cs-check + phpstan + phpunit
	$(composer) cs-check
	$(composer) phpstan
	$(composer) phpunit

.PHONY: help run fix-perms docker-login docker-push docker-build bash composer-i composer-u cs-fix cs-check phpstan phpunit phpunit-filter test
