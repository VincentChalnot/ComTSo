load 'deploy' if respond_to?(:namespace) # cap2 differentiator

require 'capifony_symfony2'

set :stages, %w(comtso gamayati)
set :default_stage, "comtso"
set :stage_dir,     "app/config/capifony"

set :application,          "comtso"
set :use_sudo,             false
set :controllers_to_clear, ['none']
set :composer_options,  "--verbose --prefer-dist --optimize-autoloader --no-progress"

set :writable_dirs,        ["app/cache", "app/logs", "app/data"]
set :webserver_user,       "www-data"
set :permission_method,    :chown
set :use_set_permissions,  true

set :dump_assetic_assets, true
set :shared_files,        ["app/config/parameters.yml"]
set :shared_children,     [app_path + "/logs", app_path + "/data", web_path + "/uploads", "vendor"]
set :use_composer,        true
set :update_vendors,      false

set :repository,       "git@github.com:VincentChalnot/ComTSo.git"
set :scm,              :git
set :deploy_via,       :remote_cache
set :model_manager,    "doctrine"

set :keep_releases,  3

require 'capistrano/ext/multistage'
