require "socket"
require "thread"
require "openssl"
require "zlib"

class String
  def to_hex
    self.split("").map { |b| sprintf("%02X", b.ord) }.join
  end
end

module Lumberjack

  SEQUENCE_MAX = (2**32-1).freeze

  class Client
    def initialize(opts={})
      @opts = {
        :port => 0,
        :addresses => [],
        :ssl_certificate => nil,
        :window_size => 5000
      }.merge(opts)

      @opts[:addresses] = [@opts[:addresses]] if @opts[:addresses].class == String
      raise "Must set a port." if @opts[:port] == 0
      raise "Must set atleast one address" if @opts[:addresses].empty? == 0
      raise "Must set a ssl certificate or path" if @opts[:ssl_certificate].nil?

      @socket = connect

    end

    private
    def connect
      addrs = @opts[:addresses].shuffle
      begin
        raise "Could not connect to any hosts" if addrs.empty?
        opts = @opts
        opts[:host] = addrs.pop
        Lumberjack::Socket.new(opts)
      rescue *[Errno::ECONNREFUSED,SocketError] => e
        puts e.inspect
        retry
      end
    end

    public
    def write(hash)
      @socket.write_hash(hash)
    end

    public
    def host
      @socket.host
    end
  end

  class Socket

    # Create a new Lumberjack SecureSocket.
    #
    # - options is a hash. Valid options are:
    #
    # * :port - the port to listen on
    # * :address - the host/address to bind to
    # * :ssl_certificate - the path to the ssl cert to use
    attr_reader :sequence
    attr_reader :window_size
    attr_reader :host
    def initialize(opts={})
      @sequence = 0
      @last_ack = 0
      @opts = {
        :port => 0,
        :host => "127.0.0.1",
        :ssl_certificate => nil,
        :window_size => 5000
      }.merge(opts)
      @host = @opts[:host]
      @window_size = @opts[:window_size]

      connection_start(opts)
    end

    private
    def connection_start(opts)
      tcp_socket = TCPSocket.new(opts[:host], opts[:port])
      @socket = OpenSSL::SSL::SSLSocket.new(tcp_socket)
      @socket.connect
      data = ["1", "W", @window_size].pack("AAN")
      puts "debug: [1, W, #{@window_size}] = #{data.to_hex}"
      @socket.syswrite(data)
    end

    private 
    def inc
      @sequence = 0 if @sequence + 1 > Lumberjack::SEQUENCE_MAX
      @sequence = @sequence + 1
    end

    private
    def write(msg)
      # compress = Zlib::Deflate.deflate(msg)
      # @socket.syswrite(["1","C",compress.bytesize,compress].pack("AANA#{compress.bytesize}"))
      @socket.syswrite(msg)
    end

    public
    def write_hash(hash)
      frame = Encoder.to_compressed_frame(hash, inc)
      puts "debug: #{hash.inspect} = #{frame.to_hex}"
      ack if unacked_sequence_size >= @window_size
      write frame
    end

    private
    def ack
      _, type = read_version_and_type
      raise "Whoa we shouldn't get this frame: #{type}" if type != "A"
      @last_ack = read_last_ack
      ack if unacked_sequence_size >= @window_size
    end

    private
    def unacked_sequence_size
      sequence - (@last_ack + 1)
    end

    private
    def read_version_and_type
      version = @socket.read(1)
      type    = @socket.read(1)
      [version, type]
    end
    private
    def read_last_ack
      @socket.read(4).unpack("N").first
    end

    private
    def to_frame(hash, sequence)
      frame = ["1", "D", sequence]
      pack = "AAN"
      keys = deep_keys(hash)
      frame << keys.length
      pack << "N"
      keys.each do |k|
        val = deep_get(hash,k)
        key_length = k.bytesize
        val_length = val.bytesize
        frame << key_length
        pack << "N"
        frame << k
        pack << "A#{key_length}"
        frame << val_length
        pack << "N"
        frame << val
        pack << "A#{val_length}"
      end
      frame.pack(pack)
    end

    private
    def deep_get(hash, key="")
      return hash if key.nil?
      deep_get(
        hash[key.split('.').first],
        key[key.split('.').first.length+1..key.length]
      )
    end

    private
    def deep_keys(hash, prefix="")
      keys = []
      hash.each do |k,v|
        keys << "#{prefix}#{k}" if v.class == String
        keys << deep_keys(hash[k], "#{k}.") if v.class == Hash
      end
      keys.flatten
    end
  end

  module Encoder
    def self.to_compressed_frame(hash, sequence)
      compress = Zlib::Deflate.deflate(to_frame(hash, sequence))
      puts "debug: compressed frame=#{compress.to_hex}"
      packed = ["1", "C", compress.bytesize, compress].pack("AANA#{compress.length}")
      puts "debug: packed compressed frame=#{packed.to_hex}"
      packed
    end

    def self.to_frame(hash, sequence)
      frame = ["1", "D", sequence]
      pack = "AAN"
      keys = deep_keys(hash)
      frame << keys.length
      pack << "N"
      keys.each do |k|
        val = deep_get(hash,k)
        key_length = k.bytesize
        val_length = val.bytesize
        frame << key_length
        pack << "N"
        frame << k
        pack << "A#{key_length}"
        frame << val_length
        pack << "N"
        frame << val
        pack << "A#{val_length}"
      end
      packed = frame.pack(pack)
      puts "debug: frame=#{frame.inspect}; pack=#{pack.inspect}; packed frame=#{packed.to_hex}"
      packed
    end

    private
    def self.deep_get(hash, key="")
      return hash if key.nil?
      deep_get(
        hash[key.split('.').first],
        key[key.split('.').first.length+1..key.length]
      )
    end
    private
    def self.deep_keys(hash, prefix="")
      keys = []
      hash.each do |k,v|
        keys << "#{prefix}#{k}" if v.class == String
        keys << deep_keys(hash[k], "#{k}.") if v.class == Hash
      end
      keys.flatten
    end
  end # module Encoder
end
