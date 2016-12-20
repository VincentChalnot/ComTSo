# Forum Gamayati
# see /CapFile for default config

set :domain,               "nas"
set :deploy_to,            "/home/vincent/www/comtso"
set :app_path,             "app"
set :user,                 "vincent"
set :branch,               "master"
#ssh_options[:port] =       "22"

role :web,                 domain
role :app,                 domain
role :db,                  domain, :primary => true

# Be more verbose by uncommenting the following line
logger.level = Logger::MAX_LEVEL
