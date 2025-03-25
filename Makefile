.PHONY: help
help: ## This help
	@grep -Eh '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-30s\033[0m %s\n", $$1, $$2}'

.env:
	@read -p "Remote server address: " REMOTE_ADDR && \
	printf '%s\n' "REMOTE_ADDR=$${REMOTE_ADDR}" >> .env
	@read -p "Remote deployment folder: " REMOTE_FOLDER && \
	printf '%s\n' "REMOTE_FOLDER=$${REMOTE_FOLDER}" >> .env

include .env

.PHONY: deploy
deploy: .env ## Deploy on remote host
	rsync -av --ignore-existing '.docker/prod/' '${REMOTE_ADDR}:${REMOTE_FOLDER}'
	ssh '${REMOTE_ADDR}' "cd '${REMOTE_FOLDER}' && make deploy"

.PHONY: backup
backup: .env ## Backup remote database and files
	# Create database backup
	ssh '${REMOTE_ADDR}' "cd '${REMOTE_FOLDER}' && docker compose exec mysql sh -c 'mysqldump -u\"\$$MYSQL_USER\" -p\"\$$MYSQL_PASSWORD\" \"\$$MYSQL_DATABASE\"' > '${SHARED_FOLDER}/app/data/dumps/${CURRENT_DATE}.sql' && gzip '${SHARED_FOLDER}/app/data/dumps/${CURRENT_DATE}.sql'"
	# rsync shared folder to local, excluding app/data/photos/cache:
	rsync -aqz --exclude 'photos/cache/' '${REMOTE_ADDR}:shared/app/data/' './app/data/'
