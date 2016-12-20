load 'deploy' if respond_to?(:namespace) # cap2 differentiator

require 'capifony_symfony2'

set :stages, %w(comtso nas gamayati)
set :default_stage, "comtso"
set :stage_dir,     "app/config/capifony"

set :application,          "comtso"
set :use_sudo,             false
set :controllers_to_clear, ['app_dev.php']
set :composer_options,  "--verbose --prefer-dist --optimize-autoloader --no-progress"

set :writable_dirs,        ["app/cache", "app/logs", "app/data"]

set :dump_assetic_assets, true
set :shared_files,        ["app/config/parameters.yml"]
set :shared_children,     [app_path + "/logs", app_path + "/data", web_path + "/uploads"]
set :use_composer,        true
set :composer_bin,        "composer"
set :update_vendors,      false
set :copy_vendors,        true

set :repository,       "git@github.com:VincentChalnot/ComTSo.git"
set :scm,              :git
set :deploy_via,       :remote_cache
set :model_manager,    "doctrine"

set :keep_releases,  3

require 'capistrano/ext/multistage'

before "symfony:composer:install", "make:install"
after :deploy, "deploy:cleanup", "phpfpm:restart"

namespace :phpfpm do
    task :restart, :roles => :app do
      run "sudo service php5-fpm restart"
    end
end

namespace :make do
    task :install, :roles => :app do
      run "cd #{latest_release} && make install"
    end
end
