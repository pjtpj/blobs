using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.IO;
using System.Text;
using System.Text.RegularExpressions;

using Core;

namespace BlobClient
{
	class Program
	{
		static void Usage()
		{
			Console.WriteLine("Usage: Hostname Password command folder [file1 file2 ...]");
			Console.WriteLine("Command can be 'exists', 'delete', 'rename', 'upload' or 'list'");
		}

		static string _regexFormats = @"gif|jpg|png|pdf|swf"; // Copied from ImageFormats.cs
		static Regex _fileNameRegex = new Regex(string.Format(@"^(\d+)\.({0})$", _regexFormats)); // Copied from ListingImage.cs

		static void Main(string[] args)
		{
			try
			{
				if (args.Length < 4)
				{
					Usage();
					return;
				}

				// File.Move(@"\\server\BlobTest\files\test.txt", @"\\server\BlobTest\files\test2.txt");
				// File.Exists(@"\\server\BlobTest\files\blobtest.teztech.com\folder1\originals\flood.jpg");
				// File.Delete(@"\\server\BlobTest\files\blobtest.teztech.com\folder1\originals\flood.jpg");
				// File.Delete(@"\\server\BlobTest\files\blobtest.teztech.com\folder1\originals\flood2.jpg");
				// return;


				string hostname = args[0];
				string password = args[1];
				string command  = args[2];
				string folder   = args[3];

				if (command == "upload")
				{
					for (int i = 4; i < args.Length; i++)
					{
						if (!File.Exists(args[i]))
						{
							Console.WriteLine("ERROR: Arg '{0}' is not a file", args[i]);
							return;
						}
					}
				}

				Core.BlobClient blobClient = new Core.BlobClient(hostname, password);

				if (command == "list")
				{
					Console.WriteLine("Listing files from folder '{0}'...", folder);
					Set<string> files = blobClient.ListFiles(folder);
					foreach (string file in files)
					{
						// Trace.Assert(_fileNameRegex.IsMatch(file));
						Console.WriteLine("    {0}", file);
					}
					Console.WriteLine("{0} files listed", files.Count);
					return;
				}

				if (command == "rename")
				{
					if (args.Length != 6)
					{
						Usage();
						return;
					}

					blobClient.RenameFile(folder, args[4], args[5]);
					Console.WriteLine("{0} deleted: {1}", args[4], blobClient.Response);
					return;
				}

				for (int i = 4; i < args.Length; i++)
				{
					switch (command)
					{
						case "exists":
							Console.WriteLine("{0} {1}: {2}", args[i], blobClient.FileExists(folder, args[i]) ? "exists" : "does not exist", blobClient.Response);
							break;

						case "delete":
							blobClient.DeleteFile(folder, args[i]);
							Console.WriteLine("{0} deleted: {1}", args[i], blobClient.Response);
							break;

						case "upload":
							byte[] fileBytes = File.ReadAllBytes(args[i]);
							blobClient.UploadBlob(folder, Path.GetFileName(args[i]), fileBytes);
							Console.WriteLine("{0} uploaded: {1}", Path.GetFileName(args[i]), blobClient.Response);
							break;

						default:
							Console.WriteLine("ERROR: Invalid command '{0}'", command);
							Console.WriteLine();
							Usage();
							return;
					}
				}
			}
			catch (Exception e)
			{
				Console.Write("ERROR: A fatal exception occured: {0}: {1}", e.GetType().Name, e.Message);
				if (e.InnerException != null)
					Console.Write(" ({0}: {1})", e.InnerException.GetType().Name, e.InnerException.Message);
				Console.WriteLine("");
			}
		}
	}
}
