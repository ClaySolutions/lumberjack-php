$: << '.'

require 'client'

client = Lumberjack::Client.new({
  :port => 2323,
  # :addresses => ['192.168.56.101'],
  :addresses => ['192.168.56.101'],
  # :addresses => ['192.168.56.101', '127.0.0.1'],
  :ssl_certificate => './testssl.crt',
  :window_size => 5000
})

client.write({'line'=> 'testmessage', 'param1' => 'value1'})