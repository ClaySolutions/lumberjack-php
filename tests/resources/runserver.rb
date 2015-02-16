$: << '.'

require 'server'

server = Lumberjack::Server.new({
  :port => 2323,
  :host => '0.0.0.0',
  :ssl_certificate => './testssl.crt',
  :ssl_key => './testssl.key'
})

server.run do |data|
  puts "(#{data.class}) #{data.inspect}"
end