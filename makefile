COMPOSER := $(shell command -v composer 2> /dev/null)
BUNDLER := $(shell command -v bundle 2> /dev/null)
NPM := $(shell command -v npm 2> /dev/null)

required:
ifndef COMPOSER
    $(error "composer is not available please install composer https://getcomposer.org/")
endif
ifndef NPM
    $(error "npm is not available please install npm using your package manager")
endif
	composer install
	npm install

install: required
	php app/console cache:clear --env=prod --no-debug
	php app/console assets:install web --symlink
	php app/console assetic:dump
	php app/console assetic:dump --env=prod --no-debug
	php app/console doctrine:schema:validate 2> /dev/null
	
bundle: required
ifndef BUNDLER
    $(error "bundler is not available please install bundler using gem")
endif
	bundle install

deploy: bundle
	bundle exec cap deploy

build:	bundle
	bundle exec compass compile
