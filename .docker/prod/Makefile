.PHONY: help
help: ## This help
	@grep -Eh '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-30s\033[0m %s\n", $$1, $$2}'

.env:
	printf '%s\n' "REPOSITORY_ADDRESS=https://github.com/VincentChalnot/ComTSo.git" >> .env
	# Generate random MYSQL_PASSWORD:
	printf '%s\n' "MYSQL_PASSWORD=$$(openssl rand -hex 16)" >> .env
	# Domain name for the project:
	printf '%s\n' "DOMAIN_NAME=comtso.fr" >> .env

include .env

CURRENT_DATE := $(shell date '+%y%m%d%H%M')
RELEASE:= releases/${CURRENT_DATE}

repository:
	git clone '${REPOSITORY_ADDRESS}' repository

releases:
	mkdir -p releases

shared:
	mkdir -p shared/app/{config,data,logs} && touch shared/app/config/parameters.yml

vendor:
	mkdir -p vendor

compose.yaml: repository
	cp repository/.docker/prod/compose.yaml .

.PHONY: pre-deploy
pre-deploy: .env compose.yaml releases shared vendor ## Deploy on remote host
	# Update repository
	git -C 'repository' pull
	# Copy repository to release folder
	cp -a 'repository/.' '${RELEASE}'
	# Remove .git folder
	rm -rf '${RELEASE}/.git'
	# Create symbolic link for staging
	rm -rf 'staging' && ln -sf '${RELEASE}' 'staging'
	# Use backup vendor directory
	rsync -a 'vendor/' '${RELEASE}/vendor/'
	# Launch composer install in staging
	docker compose run --rm --build staging
	# Backup vendor directory
	rsync -a '${RELEASE}/vendor/' 'vendor/'

.PHONY: deploy
deploy: pre-deploy
	rm -rf 'current' && ln -sf '${RELEASE}' 'current'
	# Ensure docker compose is up
	docker compose up --build --force-recreate --remove-orphans -d
