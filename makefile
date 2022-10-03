.PHONY: help
help: ## This help
	@grep -Eh '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-30s\033[0m %s\n", $$1, $$2}'

.env:
	printf '%s\n' "REPOSITORY_ADDRESS=https://github.com/VincentChalnot/ComTSo.git" >> .env
	@read -p "Remote server address: " REMOTE_ADDR && \
	printf '%s\n' "REMOTE_ADDR=$${REMOTE_ADDR}" >> .env
	@read -p "Remote deployment folder: " REMOTE_FOLDER && \
	printf '%s\n' "REMOTE_FOLDER=$${REMOTE_FOLDER}" >> .env

include .env

REPOSITORY_FOLDER := ${REMOTE_FOLDER}/repository
SHARED_FOLDER := ${REMOTE_FOLDER}/shared
CURRENT_DATE := $(shell date '+%y%m%d%H%M')
RELEASE:= ${REMOTE_FOLDER}/releases/${CURRENT_DATE}

.PHONY: deploy
deploy: .env ## Deploy on remote host
	# Ensure base folder exists
	ssh '${REMOTE_ADDR}' "mkdir -p '${REMOTE_FOLDER}'"
	# Check if repository is initialized
ifeq ($(shell ssh '${REMOTE_ADDR}' "cd '${REPOSITORY_FOLDER}' && git rev-parse --is-inside-work-tree"), true)
	# Update repository
	ssh '${REMOTE_ADDR}' "cd '${REPOSITORY_FOLDER}' && git pull"
else
	# Initialize repository
	ssh '${REMOTE_ADDR}' "git clone ${REPOSITORY_ADDRESS} '${REPOSITORY_FOLDER}'"
endif
	# Create release folder
	ssh '${REMOTE_ADDR}' "mkdir -p '${RELEASE}'"
	# Copy repository to release folder
	ssh '${REMOTE_ADDR}' "cp -a '${REPOSITORY_FOLDER}/.' '${RELEASE}'"
	# Remove .git folder
	ssh '${REMOTE_ADDR}' "rm -rf '${RELEASE}/.git'"
	# Ensure all required folder exists for shared elements
	ssh '${REMOTE_ADDR}' "mkdir -p ${SHARED_FOLDER}/app/{config,data,logs} && touch '${SHARED_FOLDER}/app/config/parameters.yml'"
	# Create symbolic link for staging
	ssh '${REMOTE_ADDR}' "rm -rf '${REMOTE_FOLDER}/staging' && ln -sf '${RELEASE}' '${REMOTE_FOLDER}/staging'"
	# Launch composer install in staging
	ssh '${REMOTE_ADDR}' "cd '${REMOTE_FOLDER}' && docker compose run --rm staging"
	# Deploy
	ssh '${REMOTE_ADDR}' "rm -rf '${REMOTE_FOLDER}/current' && ln -sf '${RELEASE}' '${REMOTE_FOLDER}/current'"
	# Ensure docker compose is up
	ssh '${REMOTE_ADDR}' "cd '${REMOTE_FOLDER}' && docker compose up --build -d"
